/**
 * DuaRTE — shared QR-scanner helpers.
 *
 * Before this file existed, three different pages (verify.php's main
 * scanner, verify.php's per-item asset scanner, and asset_audit.php's
 * scanner) each had their own copy-pasted version of: the "camera
 * won't focus this close" Tagalog hint, the secure-context/library
 * checks, the facingMode -> getCameras() -> back-camera -> first-camera
 * fallback chain, and the query-param extraction for a scanned URL.
 * Fixing a bug in one used to mean remembering to fix it in three
 * places. This file is the single copy; the three pages just call in.
 */
window.DuarteQR = (function () {
  var FOCUS_HINT_LONG = 'Gumagamit ng laptop webcam? Kadalasang fixed-focus lang ito \u2014 ilayo ang QR ng mga 20\u201330cm (hindi arm\u2019s length na malapit) at hintaying mag-focus bago ito basahin nang maayos.';
  var FOCUS_HINT_SHORT = 'Gumagamit ng laptop webcam? Ilayo ang QR ng ~20\u201330cm para maka-focus.';

  /** Fills every element carrying one of the two hint classes with the
   *  shared Tagalog copy, so the text itself only has to live here. */
  function injectFocusHints(root) {
    (root || document).querySelectorAll('.qr-focus-hint-long').forEach(function (el) {
      el.textContent = FOCUS_HINT_LONG;
    });
    (root || document).querySelectorAll('.qr-focus-hint-short').forEach(function (el) {
      el.textContent = FOCUS_HINT_SHORT;
    });
  }

  function isSecureContext() {
    return window.isSecureContext !== undefined
      ? window.isSecureContext
      : (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1');
  }

  /** Pulls a query-param value out of a decoded QR payload if it looks
   *  like a URL (scanned tag/token links), otherwise returns the raw
   *  decoded text as-is (manual paste, or a bare tag/token). A base is
   *  required for new URL() since our QR content is a relative path. */
  function extractParam(decodedText, paramName) {
    try {
      var url = new URL(decodedText, window.location.origin);
      var v = url.searchParams.get(paramName);
      if (v) return v;
    } catch (e) { /* not a URL at all, fall through */ }
    return decodedText;
  }

  /** Checks the two most common reasons a scanner never appears
   *  (library failed to load, or the page isn't in a secure context)
   *  and reports which one via showStatus. Returns false if either
   *  check fails so the caller knows not to try starting the camera. */
  function checkPrereqs(showStatus) {
    if (typeof Html5Qrcode === 'undefined') {
      showStatus('Could not load the scanner. Check your connection and refresh, or use manual entry below.');
      return false;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !isSecureContext()) {
      showStatus('Camera access requires a secure connection or device permission. Enter the code manually below.');
      return false;
    }
    return true;
  }

  /** Standard scan config used everywhere: 1280x720 ideal capture (the
   *  default 640x480 is soft on QHD-capable webcams) with the rear
   *  camera preferred on mobile. fps is raised from the library default
   *  so a decode is attempted more often while the camera is still
   *  racking focus. useBarCodeDetectorIfSupported is explicitly OFF —
   *  it defaults to true in this library version and there's a known
   *  bug (mebjas/html5-qrcode#386) where the native BarcodeDetector
   *  path refuses to decode QR codes at all on some Chromium builds
   *  (barcodes still work, QR doesn't). Forcing it off keeps every
   *  browser on the reliable pure-JS zxing-js decoder. */
  function buildScanConfig(qrboxSize) {
    return {
      fps: 20,
      qrbox: qrboxSize || 220,
      videoConstraints: {
        facingMode: 'environment',
        width: { ideal: 1280 },
        height: { ideal: 720 }
      },
      useBarCodeDetectorIfSupported: false,
      experimentalFeatures: {
        useBarCodeDetectorIfSupported: false
      }
    };
  }

  /** Starts an Html5Qrcode instance, falling back from the facingMode
   *  constraint (unsupported on some devices/browsers) to picking a
   *  camera whose label says "back"/"rear"/"environment", and finally
   *  to just the first camera available. Every failure mode reports a
   *  distinct, actionable message instead of failing silently.
   *  @param {Html5Qrcode} qr
   *  @param {object} scanConfig
   *  @param {function} onScan
   *  @param {object} cb  { onStarted(), onNoCamera(), onError(err), onDenied() } — all optional
   */
  function startWithFallback(qr, scanConfig, onScan, cb) {
    cb = cb || {};
    qr.start({ facingMode: 'environment' }, scanConfig, onScan).then(function () {
      if (cb.onStarted) cb.onStarted();
    }).catch(function () {
      Html5Qrcode.getCameras().then(function (cameras) {
        if (!cameras || !cameras.length) {
          if (cb.onNoCamera) cb.onNoCamera();
          return;
        }
        var back = cameras.find(function (c) { return /back|rear|environment/i.test(c.label || ''); });
        qr.start((back || cameras[0]).id, scanConfig, onScan).then(function () {
          if (cb.onStarted) cb.onStarted();
        }).catch(function (err2) {
          if (cb.onError) cb.onError(err2);
        });
      }).catch(function (err3) {
        // getCameras() itself failing doesn't necessarily mean the user
        // denied access — it can just as easily mean the camera is
        // locked by another app/tab (NotReadableError), there's no
        // camera hardware at all (NotFoundError), or some other
        // non-permission failure. Only report "denied" when the
        // browser actually says so, so the message people see matches
        // what really happened instead of always blaming permissions.
        var name = err3 && err3.name;
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
          if (cb.onNoCamera) cb.onNoCamera();
        } else if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
          if (cb.onDenied) cb.onDenied();
        } else if (cb.onError) {
          cb.onError(err3);
        } else if (cb.onDenied) {
          cb.onDenied();
        }
      });
    });
  }

  // Matches generate_unique_qr_token() (bin2hex(random_bytes(16))) and
  // generate_unique_asset_tag() ('AST-' . strtoupper(bin2hex(random_bytes(6))))
  // in includes/functions.php / includes/assets.php. Kept in sync with
  // those two generators — if their format ever changes, update here too.
  var TOKEN_RE = /^[0-9a-f]{32}$/i;
  var TAG_RE = /^AST-[0-9a-f]{12}$/i;

  /** Decides whether scanned/pasted text actually looks like something
   *  DuaRTE generated, instead of just assuming any unrecognized text
   *  is a requisition token. Returns { type: 'token'|'tag', value } for
   *  a recognized code, or null for anything else (a QR from another
   *  system, a random URL, junk text, etc.) so callers can show an
   *  explicit "not a DuaRTE code" message rather than silently forwarding
   *  it to verify.php and getting a generic "not found" a step later. */
  function classifyCode(rawText) {
    var text = (rawText || '').trim();
    if (!text) return null;

    // A scanned URL (our own printed QR) — trust its token/tag param
    // outright, same as before, since it can only have come from a
    // link this app itself generated.
    try {
      var url = new URL(text, window.location.origin);
      var token = url.searchParams.get('token');
      var tag = url.searchParams.get('tag');
      if (token) return { type: 'token', value: token };
      if (tag) return { type: 'tag', value: tag };
      // It parsed as a URL but carries neither param — e.g. someone
      // else's QR that happens to encode a link. Don't fall through to
      // treating the whole URL text as a bare token.
      return null;
    } catch (e) { /* not a URL — check bare-code shapes below */ }

    if (TAG_RE.test(text)) return { type: 'tag', value: text.toUpperCase() };
    if (TOKEN_RE.test(text)) return { type: 'token', value: text.toLowerCase() };
    return null;
  }

  // Web Audio & Haptic Feedback (Zero external media dependencies)
  var audioCtx = null;
  function getAudioContext() {
    if (!audioCtx) {
      var AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (AudioContextClass) {
        audioCtx = new AudioContextClass();
      }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
    return audioCtx;
  }

  /** Plays a crisp high-pitch barcode scan confirmation beep + phone vibration. */
  function playSuccessFeedback() {
    if (typeof navigator !== 'undefined' && navigator.vibrate) {
      try { navigator.vibrate(75); } catch (e) {}
    }
    try {
      var ctx = getAudioContext();
      if (!ctx) return;
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(1400, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(1850, ctx.currentTime + 0.08);
      gain.gain.setValueAtTime(0.18, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.12);
    } catch (e) {}
  }

  /** Plays a distinct low double-buzz tone + double vibration for invalid/rejected scans. */
  function playErrorFeedback() {
    if (typeof navigator !== 'undefined' && navigator.vibrate) {
      try { navigator.vibrate([100, 50, 100]); } catch (e) {}
    }
    try {
      var ctx = getAudioContext();
      if (!ctx) return;
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(320, ctx.currentTime);
      osc.frequency.linearRampToValueAtTime(220, ctx.currentTime + 0.18);
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.2);
    } catch (e) {}
  }

  return {
    injectFocusHints: injectFocusHints,
    isSecureContext: isSecureContext,
    extractParam: extractParam,
    checkPrereqs: checkPrereqs,
    buildScanConfig: buildScanConfig,
    startWithFallback: startWithFallback,
    classifyCode: classifyCode,
    playSuccessFeedback: playSuccessFeedback,
    playErrorFeedback: playErrorFeedback
  };
})();
