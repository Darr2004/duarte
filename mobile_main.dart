import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:crypto/crypto.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const DuarteApp());
}

// --------------------------------------------------------------------------
// DuaRTE Executive Design Tokens (Exact Match to Web assets/css/tokens.css)
// --------------------------------------------------------------------------
class AppColors {
  // Core Shell & Structure (Deep Slate Graphite - Never harsh pitch black)
  static const Color charcoal = Color(0xFF1A2129);       // Deep Slate Graphite
  static const Color charcoal2 = Color(0xFF242E38);      // Muted Charcoal Steel
  static const Color charcoalHover = Color(0xFF2F3B47);
  static const Color charcoalBorder = Color(0xFF3A4754);

  // Interactive Muted Cognac & Warm Bronze (Low Saturation - Eye-Comfort)
  static const Color amber = Color(0xFF8C5A32);          // Muted Warm Cognac (Primary Buttons & Links)
  static const Color amberDim = Color(0xFF6E4424);       // Deep Muted Bronze
  static const Color amberGold = Color(0xFFA87442);      // Warm Champagne Bronze
  static const Color amberOnDark = Color(0xFFE2C39B);    // Soft Warm Sand (WCAG AA pass)

  // Canvas & Surfaces (Soft Titanium Mist - Zero Eye-Glare)
  static const Color paper = Color(0xFFF5F7FA);          // Soft Titanium Mist Canvas
  static const Color surface = Color(0xFFFFFFFF);        // Crisp Pure Alabaster
  static const Color surfaceSubtle = Color(0xFFEDF1F5);  // Subtle Cool Mist
  static const Color plank = Color(0xFFE2E8F0);          // Architectural Stone Framing

  // Typography Inks (High Legibility, Low Eye Strain)
  static const Color ink = Color(0xFF161E26);            // Deep Slate Ink
  static const Color inkSoft = Color(0xFF5A6876);        // Muted Slate Walnut
  static const Color inkLight = Color(0xFF8A99A8);       // Inactive labels
  static const Color line = Color(0xFFE1E6EB);           // Tailored Hairline Border
  static const Color lineStrong = Color(0xFFCBD5E1);     // Header dividers

  // Semantic Status Tints (Desaturated, Muted, Professional)
  static const Color greenOk = Color(0xFF2D6A4F);        // Desaturated Forest Pine
  static const Color greenTint = Color(0xFFEEF6F2);
  static const Color greenBorder = Color(0xFFC2E0CE);

  static const Color redDanger = Color(0xFF94382C);      // Muted Terracotta Rosewood
  static const Color redTint = Color(0xFFFAF1EF);
  static const Color redBorder = Color(0xFFEFC7C1);

  static const Color amberTint = Color(0xFFFAF4EB);      // Muted Warm Honey
  static const Color amberBorder = Color(0xFFEDDCBE);

  static const Color blueInfo = Color(0xFF2E5370);       // Slate Aegean Navy
  static const Color blueTint = Color(0xFFEEF4F8);
  static const Color blueBorder = Color(0xFFC5D9E8);
}

// --------------------------------------------------------------------------
// Bilingual Localization Engine (Tagalog / English)
// --------------------------------------------------------------------------
enum Language { tl, en }

class AppLanguageNotifier extends ChangeNotifier {
  Language _current = Language.tl;

  Language get current => _current;
  bool get isTagalog => _current == Language.tl;
  String get code => _current == Language.tl ? 'tl' : 'en';

  AppLanguageNotifier() {
    _loadLanguage();
  }

  Future<void> _loadLanguage() async {
    final prefs = await SharedPreferences.getInstance();
    final lang = prefs.getString('app_language');
    if (lang == 'en') {
      _current = Language.en;
    } else {
      _current = Language.tl;
    }
    notifyListeners();
  }

  Future<void> setLanguage(Language lang) async {
    if (_current == lang) return;
    _current = lang;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('app_language', lang == Language.en ? 'en' : 'tl');
  }

  void toggle() {
    setLanguage(_current == Language.tl ? Language.en : Language.tl);
  }

  String t(String key) => AppStrings.get(key, _current);
  String choice(String tl, String en) => _current == Language.tl ? tl : en;
}

final globalLanguage = AppLanguageNotifier();

class AppStrings {
  static const Map<String, Map<Language, String>> _values = {
    // General / Common
    'app_name': {Language.tl: 'DuaRTE', Language.en: 'DuaRTE'},
    'app_subtitle': {Language.tl: 'Logistik ng Sasakyan at Gamit', Language.en: 'Fleet Requests & Equipment Borrowing'},
    'save': {Language.tl: 'I-save', Language.en: 'Save'},
    'cancel': {Language.tl: 'Kanselahin', Language.en: 'Cancel'},
    'close': {Language.tl: 'Isara', Language.en: 'Close'},
    'confirm': {Language.tl: 'Kumpirmahin', Language.en: 'Confirm'},
    'approve': {Language.tl: 'Aprubahan', Language.en: 'Approve'},
    'reject': {Language.tl: 'Tanggihan', Language.en: 'Reject'},
    'Aprubahan': {Language.tl: 'Aprubahan', Language.en: 'Approve'},
    'Tanggihan': {Language.tl: 'Tanggihan', Language.en: 'Reject'},
    'Kanselahin': {Language.tl: 'Kanselahin', Language.en: 'Cancel'},
    'Isara': {Language.tl: 'Isara', Language.en: 'Close'},
    'loading': {Language.tl: 'Naglo-load...', Language.en: 'Loading...'},
    'search': {Language.tl: 'Maghanap...', Language.en: 'Search...'},
    'filter': {Language.tl: 'Salain', Language.en: 'Filter'},
    'refresh': {Language.tl: 'I-refresh', Language.en: 'Refresh'},
    'language': {Language.tl: 'Wika', Language.en: 'Language'},
    'switch_lang': {Language.tl: 'Palitan ang Wika', Language.en: 'Change Language'},
    'tagalog': {Language.tl: 'Tagalog', Language.en: 'Tagalog'},
    'english': {Language.tl: 'English', Language.en: 'English'},

    // Login Screen
    'login_title': {Language.tl: 'Mag-Log In', Language.en: 'Sign In'},
    'login_subtitle': {Language.tl: 'Ilagay ang inyong kredensyal', Language.en: 'Enter your account credentials'},
    'username': {Language.tl: 'Pangalan ng User', Language.en: 'Username'},
    'username_hint': {Language.tl: 'Ilagay ang username', Language.en: 'Enter username'},
    'password': {Language.tl: 'Password', Language.en: 'Password'},
    'password_hint': {Language.tl: 'Ilagay ang password', Language.en: 'Enter password'},
    'show_password': {Language.tl: 'Ipakita ang password', Language.en: 'Show password'},
    'hide_password': {Language.tl: 'Itago ang password', Language.en: 'Hide password'},
    'login_btn': {Language.tl: 'MAG-LOG IN', Language.en: 'LOG IN'},
    'login_empty_error': {Language.tl: 'Kailangan ang kredensyal.', Language.en: 'Credentials required.'},
    'server_settings': {Language.tl: 'Server Settings', Language.en: 'Server Settings'},
    'server_saved': {Language.tl: 'Nai-save ang Server URL.', Language.en: 'Server URL saved.'},
    'login_server_error': {Language.tl: 'Hindi makakonek sa server.', Language.en: 'Server connection failed.'},
    // PIN Authentication
    'pin_title': {Language.tl: 'Mabilisang 4-Digit PIN', Language.en: '4-Digit Quick PIN'},
    'pin_enter': {Language.tl: 'Ilagay ang 4-digit PIN', Language.en: 'Enter 4-digit PIN'},
    'pin_setup_title': {Language.tl: 'Magtakda ng 4-Digit PIN', Language.en: 'Set 4-Digit PIN'},
    'pin_setup_desc': {Language.tl: 'Para sa mabilisang pag-access.', Language.en: 'For secure quick access.'},
    'pin_confirm': {Language.tl: 'Kumpirmahin ang 4-digit PIN', Language.en: 'Confirm 4-digit PIN'},
    'pin_mismatch': {Language.tl: 'Hindi nagtutugma ang PIN.', Language.en: 'PINs do not match.'},
    'pin_saved': {Language.tl: 'Nai-save ang PIN.', Language.en: 'PIN saved successfully.'},
    'pin_skip': {Language.tl: 'Laktawan Muna', Language.en: 'Skip for Now'},
    'pin_switch_account': {Language.tl: 'Lumipat ng ibang account', Language.en: 'Switch account'},
    'pin_use_password': {Language.tl: 'Gamitin ang Password', Language.en: 'Log In with Password'},
    'pin_change': {Language.tl: 'Palitan ang 4-Digit PIN', Language.en: 'Change 4-Digit PIN'},
    'pin_change_subtitle': {Language.tl: 'Para sa mabilisang login', Language.en: 'For quick mobile login'},

    // Navigation Tabs (Concise, single-line labels)
    'nav_release': {Language.tl: 'Release', Language.en: 'Release'},
    'nav_loans': {Language.tl: 'Hiram', Language.en: 'Loans'},
    'nav_stock': {Language.tl: 'Stock', Language.en: 'Stock'},
    'nav_approvals': {Language.tl: 'Aprubahan', Language.en: 'Approvals'},
    'nav_requests': {Language.tl: 'Kahilingan', Language.en: 'Requests'},
    'nav_catalog': {Language.tl: 'Katalogo', Language.en: 'Catalog'},
    'nav_special_po': {Language.tl: 'Special PO', Language.en: 'Special PO'},
    'nav_profile': {Language.tl: 'Profile', Language.en: 'Profile'},
    'nav_scan_item': {Language.tl: 'Scan', Language.en: 'Scan'},
    'nav_dashboard': {Language.tl: 'Dashboard', Language.en: 'Dashboard'},
    'nav_my_activity': {Language.tl: 'Aking Gawain', Language.en: 'My Activity'},

    // Inventory Verify & Release Screen
    'inv_hero_title': {Language.tl: 'SMART SCANNER (RELEASE O GAMIT)', Language.en: 'SMART SCANNER (RELEASE OR ITEM)'},
    'inv_hero_subtitle': {Language.tl: 'Itapat sa QR ng Driver, Item Tag, o Barcode.', Language.en: 'Scan Driver QR, Item Tag, or Barcode.'},
    'inv_scan_btn': {Language.tl: 'BUKSAN ANG SCANNER', Language.en: 'OPEN CAMERA SCANNER'},
    'inv_manual_label': {Language.tl: 'O ilagay ang code:', Language.en: 'Or enter code manually:'},
    'inv_manual_hint': {Language.tl: 'Hal. REQ-2026-001, AST-... o barcode', Language.en: 'E.g. REQ-2026-001, AST-... or barcode'},
    'inv_lookup_btn': {Language.tl: 'Hanapin', Language.en: 'Search'},
    'inv_ready_title': {Language.tl: 'Handa nang I-release', Language.en: 'Ready for Release'},
    'inv_no_ready': {Language.tl: 'Walang nakabinbing release ngayon.', Language.en: 'No pending releases found.'},
    'inv_release_btn': {Language.tl: 'I-RELEASE ANG MGA GAMIT', Language.en: 'RELEASE ITEMS'},
    'inv_release_confirm_title': {Language.tl: 'Kumpirmahin ang Pag-release', Language.en: 'Confirm Release'},
    'inv_release_confirm_msg': {Language.tl: 'Naibigay na ang gamit?', Language.en: 'Are items handed over?'},
    'inv_release_success': {Language.tl: 'Nai-release na ang gamit!', Language.en: 'Items released successfully!'},
    'inv_found_title': {Language.tl: 'Detalye ng Requisition', Language.en: 'Requisition Details'},
    'inv_items_list': {Language.tl: 'Checklist ng Gamit:', Language.en: 'Items Checklist:'},
    'inv_qty': {Language.tl: 'Dami', Language.en: 'Qty'},
    'inv_driver': {Language.tl: 'Driver / Requester:', Language.en: 'Driver / Requester:'},
    'inv_truck': {Language.tl: 'Sasakyan / Truck:', Language.en: 'Vehicle / Truck:'},
    'inv_date': {Language.tl: 'Petsa:', Language.en: 'Date:'},
    'inv_purpose': {Language.tl: 'Layunin:', Language.en: 'Purpose:'},
    'inv_scan_item_barcode': {Language.tl: 'I-scan ang QR Code', Language.en: 'Scan QR Code'},
    'inv_verify_all': {Language.tl: 'I-verify Lahat', Language.en: 'Verify All'},
    'inv_unverify_all': {Language.tl: 'I-reset', Language.en: 'Reset'},
    'inv_verified_status': {Language.tl: 'Na-verify', Language.en: 'Verified'},
    'inv_unverified_status': {Language.tl: 'I-tap para i-verify', Language.en: 'Tap to verify'},
    'inv_partial_warning_title': {Language.tl: 'Bahagyang Pag-Release', Language.en: 'Partial Release'},
    'inv_partial_missing_items': {Language.tl: 'Mga Kulang na Gamit:', Language.en: 'Shortfall / Missing Items:'},
    'inv_partial_driver_protect': {Language.tl: 'Na-verify lamang ang ibabawas.', Language.en: 'Only verified items deduct.'},
    'inv_partial_reason_label': {Language.tl: 'Dahilan ng kakulangan:', Language.en: 'Reason for shortfall:'},
    'inv_partial_btn': {Language.tl: 'I-release ang Na-verify', Language.en: 'Release Verified Only'},

    // Camera Scanner Modal
    'cam_scanner_title': {Language.tl: 'Smart Scanner', Language.en: 'Smart Scanner'},
    'cam_guide': {Language.tl: 'Itapat sa QR ng Driver o Tag/Barcode ng Gamit.', Language.en: 'Align Driver QR or Item Tag/Barcode in frame.'},
    'cam_torch': {Language.tl: 'Ilaw / Flash', Language.en: 'Torch / Flash'},
    'cam_flip': {Language.tl: 'Palitan ang Camera', Language.en: 'Switch Camera'},

    // Item Stock Check (QR Scan) Screen
    'isc_hero_title': {Language.tl: 'SMART SCANNER (KONDISYON AT STOCK)', Language.en: 'SMART SCANNER (CONDITION & STOCK)'},
    'isc_hero_subtitle': {Language.tl: 'Itapat sa barcode, asset tag, o QR ng driver.', Language.en: 'Scan barcode, asset tag, or driver QR.'},
    'isc_scan_btn': {Language.tl: 'BUKSAN ANG SCANNER', Language.en: 'OPEN SCANNER'},
    'isc_manual_label': {Language.tl: 'O ilagay ang code:', Language.en: 'Or enter code manually:'},
    'isc_manual_hint': {Language.tl: 'Hal. AST-..., PRT-005, o REQ-...', Language.en: 'E.g. AST-..., PRT-005, or REQ-...'},
    'isc_lookup_btn': {Language.tl: 'Hanapin', Language.en: 'Search'},
    'isc_not_found': {Language.tl: 'Hindi nahanap ang item.', Language.en: 'Item not found.'},
    'isc_scan_another': {Language.tl: 'I-SCAN ANG IBANG ITEM', Language.en: 'SCAN ANOTHER ITEM'},
    'isc_stock_label': {Language.tl: 'Kasalukuyang Stock', Language.en: 'Current Stock'},
    'isc_location_label': {Language.tl: 'Lokasyon sa Bodega', Language.en: 'Warehouse Location'},
    'isc_loans_label': {Language.tl: 'Kasalukuyang Hiniram', Language.en: 'Active Loans'},
    'isc_due_label': {Language.tl: 'Pinakamaagang Balik', Language.en: 'Earliest Return'},
    'isc_variants_label': {Language.tl: 'Mga Variant', Language.en: 'Variants'},
    'isc_activity_label': {Language.tl: 'Kamakailang Aktibidad', Language.en: 'Recent Activity'},
    'isc_no_activity': {Language.tl: 'Walang naitalang aktibidad.', Language.en: 'No recorded activity yet.'},

    // Asset Health & Condition Check
    'ahc_title': {Language.tl: 'Kondisyon ng Asset', Language.en: 'Asset Health Check'},
    'ahc_ok_badge': {Language.tl: 'Maayos ang Kondisyon', Language.en: 'In Good Condition'},
    'ahc_ok_sub': {Language.tl: 'Handang ipahiram sa bodega.', Language.en: 'Available in warehouse.'},
    'ahc_maint_badge': {Language.tl: 'May Sira / Inaayos', Language.en: 'Under Maintenance'},
    'ahc_maint_sub': {Language.tl: 'Kailangang kumpunihin bago gamitin.', Language.en: 'Repairs required before loan.'},
    'ahc_loan_badge': {Language.tl: 'Kasalukuyang Hiniram', Language.en: 'Currently Checked Out'},
    'ahc_missing_badge': {Language.tl: 'Nawawala sa Audit', Language.en: 'Missing from Audit'},
    'ahc_retired_badge': {Language.tl: 'Hindi Na Ginagamit', Language.en: 'Retired from Service'},
    'ahc_report_damage_btn': {Language.tl: 'I-REPORT ANG SIRA', Language.en: 'REPORT DAMAGE'},
    'ahc_mark_repaired_btn': {Language.tl: 'TAPOS NA ANG KUMPUNI', Language.en: 'MARK REPAIRED'},
    'ahc_condition_note_hint': {Language.tl: 'Ilagay ang naging sira...', Language.en: 'Describe defect or issue...'},
    'ahc_submit_report': {Language.tl: 'I-save ang Ulat', Language.en: 'Submit Report'},
    'ahc_tag_label': {Language.tl: 'Asset Tag', Language.en: 'Asset Tag'},
    'ahc_serial_label': {Language.tl: 'Serial Number', Language.en: 'Serial Number'},
    'ahc_holder_label': {Language.tl: 'May Hawak', Language.en: 'Current Holder'},
    'ahc_history_title': {Language.tl: 'Kasaysayan ng Gamit', Language.en: 'Asset History Timeline'},

    // Inventory Loans Screen
    'loan_title': {Language.tl: 'Mga Hiniram na Gamit', Language.en: 'Equipment Loans'},
    'loan_tab_active': {Language.tl: 'Kasalukuyang Hiniram', Language.en: 'Active Loans'},
    'loan_tab_returned': {Language.tl: 'Mga Naisoli Na', Language.en: 'Returned'},
    'loan_tab_overdue': {Language.tl: 'Lampas sa Araw', Language.en: 'Overdue'},
    'loan_return_btn': {Language.tl: 'TANGGAPIN ANG PAGSAULI', Language.en: 'PROCESS RETURN'},
    'loan_return_confirm': {Language.tl: 'Naisoli na nang maayos?', Language.en: 'Returned in good condition?'},
    'loan_no_items': {Language.tl: 'Walang kagamitan dito.', Language.en: 'No items recorded here.'},

    // Inventory Stock Screen
    'stock_title': {Language.tl: 'Imbentaryo sa Bodega', Language.en: 'Warehouse Stock'},
    'stock_search_hint': {Language.tl: 'Maghanap ng pangalan o code...', Language.en: 'Search name or code...'},
    'stock_available': {Language.tl: 'May Stock', Language.en: 'Available'},
    'stock_low': {Language.tl: 'Mababang Stock', Language.en: 'Low Stock'},
    'stock_out': {Language.tl: 'Ubos Na', Language.en: 'Out of Stock'},

    // Supervisor Approvals Screen
    'sup_title': {Language.tl: 'Naghihintay ng Aprubasyon', Language.en: 'Pending Approvals'},
    'sup_approve_btn': {Language.tl: 'APRUBAHAN', Language.en: 'APPROVE'},
    'sup_reject_btn': {Language.tl: 'TANGGIHAN', Language.en: 'REJECT'},
    'sup_confirm_approve': {Language.tl: 'Aprubahan ang kahilingan?', Language.en: 'Approve this requisition?'},
    'sup_reject_reason': {Language.tl: 'Dahilan ng pagtanggi:', Language.en: 'Reason for rejection:'},
    'sup_reject_hint': {Language.tl: 'Ilagay ang dahilan...', Language.en: 'Enter reason here...'},
    'sup_no_pending': {Language.tl: 'Walang nakabinbing kahilingan.', Language.en: 'No pending requisitions.'},

    // Catalog & Cart Screen
    'cat_title': {Language.tl: 'Katalogo ng Kagamitan', Language.en: 'Equipment Catalog'},
    'cat_search_hint': {Language.tl: 'Maghanap ng gamit o piyesa...', Language.en: 'Search items or parts...'},
    'cat_all': {Language.tl: 'Lahat', Language.en: 'All'},
    'cat_add': {Language.tl: 'Idagdag', Language.en: 'Add'},
    'cat_in_cart': {Language.tl: 'Nasa Cart Na', Language.en: 'In Cart'},
    'cat_cart_btn': {Language.tl: 'TINGNAN ANG CART', Language.en: 'VIEW CART'},
    'cat_checkout_title': {Language.tl: 'Aking Request Cart', Language.en: 'My Request Cart'},
    'cat_checkout_submit': {Language.tl: 'I-SUBMIT ANG REQUEST', Language.en: 'SUBMIT REQUISITION'},
    'cat_select_truck': {Language.tl: 'Pumili ng Sasakyan:', Language.en: 'Select Vehicle:'},
    'cat_purpose_label': {Language.tl: 'Layunin ng Paggamit:', Language.en: 'Work Purpose / Description:'},
    'cat_purpose_hint': {Language.tl: 'Hal. Routine maintenance', Language.en: 'E.g. Routine maintenance'},
    'cat_days_borrow': {Language.tl: 'Ilang araw hihiramin?', Language.en: 'Borrow duration (days)?'},
    'cat_cart_empty': {Language.tl: 'Walang laman ang cart.', Language.en: 'Your cart is empty.'},

    // My Requisitions Screen
    'req_title': {Language.tl: 'Aking mga Kahilingan', Language.en: 'My Requisitions'},
    'req_status_pending': {Language.tl: 'Naghihintay ng Aprubasyon', Language.en: 'Pending Approval'},
    'req_status_approved': {Language.tl: 'Handa nang Kunin', Language.en: 'Ready for Pickup'},
    'req_status_released': {Language.tl: 'Na-release Na', Language.en: 'Released'},
    'req_status_rejected': {Language.tl: 'Tinanggihan', Language.en: 'Rejected'},
    'req_status_returned': {Language.tl: 'Naisoli Na', Language.en: 'Returned'},
    'req_show_qr': {Language.tl: 'IPAKITA ANG QR CODE', Language.en: 'SHOW QR CODE'},
    'req_claim_instruction': {Language.tl: 'Ipakita sa warehouse staff.', Language.en: 'Present to warehouse staff.'},

    // Profile Screen
    'prof_title': {Language.tl: 'Profile at Setting', Language.en: 'Profile & Settings'},
    'prof_account': {Language.tl: 'Impormasyon ng Account', Language.en: 'Account Information'},
    'prof_role': {Language.tl: 'Tungkulin / Role:', Language.en: 'Designation / Role:'},
    'prof_language_section': {Language.tl: 'Pagpili ng Wika', Language.en: 'Language Preference'},
    'prof_offline_sync': {Language.tl: 'I-sync ang Offline Requests', Language.en: 'Sync Offline Requests'},
    'prof_logout': {Language.tl: 'MAG-LOG OUT', Language.en: 'LOG OUT'},
    'prof_logout_confirm': {Language.tl: 'Nais mo bang mag-log out?', Language.en: 'Confirm account logout?'},
  };

  static String get(String key, Language lang) {
    final entry = _values[key];
    if (entry == null) return key;
    return entry[lang] ?? entry[Language.tl] ?? key;
  }
}

class LanguageToggleBar extends StatelessWidget {
  const LanguageToggleBar({super.key});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: globalLanguage,
      builder: (context, _) {
        final isTl = globalLanguage.isTagalog;
        return Container(
          decoration: BoxDecoration(
            color: AppColors.surfaceSubtle,
            borderRadius: BorderRadius.circular(24),
            border: Border.all(color: AppColors.line, width: 1.5),
          ),
          padding: const EdgeInsets.all(4),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              _buildOption(
                label: '🇵🇭 Tagalog',
                selected: isTl,
                onTap: () => globalLanguage.setLanguage(Language.tl),
              ),
              const SizedBox(width: 4),
              _buildOption(
                label: '🇺🇸 English',
                selected: !isTl,
                onTap: () => globalLanguage.setLanguage(Language.en),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildOption({required String label, required bool selected, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? AppColors.amber : Colors.transparent,
          borderRadius: BorderRadius.circular(20),
          boxShadow: selected
              ? [
                  BoxShadow(
                    color: AppColors.amber.withOpacity(0.25),
                    blurRadius: 4,
                    offset: const Offset(0, 2),
                  ),
                ]
              : null,
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected ? Colors.white : AppColors.inkSoft,
            fontWeight: selected ? FontWeight.bold : FontWeight.w500,
            fontSize: 13,
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Cart Item Model & Cart Controller
// ---------------------------------------------------------
class CartItem {
  final int itemId;
  final String name;
  final String itemCode;
  final String unit;
  int quantity;
  final int maxStock;
  final bool isBorrowable;
  final String borrowMode;
  bool isBorrow;
  int requestedDays;
  String? variant;
  final String? imageUrl;

  CartItem({
    required this.itemId,
    required this.name,
    required this.itemCode,
    required this.unit,
    required this.quantity,
    required this.maxStock,
    this.isBorrowable = false,
    this.borrowMode = 'consume',
    this.isBorrow = false,
    this.requestedDays = 3,
    this.variant,
    this.imageUrl,
  });

  Map<String, dynamic> toJson() => {
        'item_id': itemId,
        'item_name': name,
        'item_code': itemCode,
        'unit': unit,
        'quantity': quantity,
        'is_borrowable': isBorrow ? 1 : 0,
        'requested_days': isBorrow ? requestedDays : null,
        'variant_selected': variant,
        'image_url': imageUrl,
      };
}

class CartNotifier extends ChangeNotifier {
  final List<CartItem> _items = [];

  List<CartItem> get items => List.unmodifiable(_items);
  int get count => _items.fold(0, (sum, i) => sum + i.quantity);
  int get uniqueItemsCount => _items.length;

  void addItem(CartItem item) {
    final existingIdx = _items.indexWhere((i) => i.itemId == item.itemId && i.variant == item.variant);
    if (existingIdx >= 0) {
      final existing = _items[existingIdx];
      final newQty = existing.quantity + item.quantity;
      existing.quantity = (existing.maxStock > 0 && newQty > existing.maxStock) ? existing.maxStock : newQty;
      existing.isBorrow = item.isBorrow;
      existing.requestedDays = item.requestedDays;
    } else {
      _items.add(item);
    }
    notifyListeners();
  }

  void updateQuantity(int itemId, int newQty) {
    final idx = _items.indexWhere((i) => i.itemId == itemId);
    if (idx >= 0) {
      if (newQty <= 0) {
        _items.removeAt(idx);
      } else {
        final item = _items[idx];
        item.quantity = (item.maxStock > 0 && newQty > item.maxStock) ? item.maxStock : newQty;
      }
      notifyListeners();
    }
  }

  void removeItem(int itemId) {
    _items.removeWhere((i) => i.itemId == itemId);
    notifyListeners();
  }

  void clear() {
    _items.clear();
    notifyListeners();
  }
}

final globalCart = CartNotifier();

// ---------------------------------------------------------
// Notifications (stock-available alerts, etc.) — polls
// api/notifications.php so a badge can show on the bell icon
// without the user having to open a screen first.
// ---------------------------------------------------------
class AppNotification {
  final int id;
  final String message;
  final String? link;
  final String createdAt;
  final bool isRead;

  AppNotification({
    required this.id,
    required this.message,
    this.link,
    required this.createdAt,
    required this.isRead,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    return AppNotification(
      id: json['id'] is int ? json['id'] : int.tryParse('${json['id']}') ?? 0,
      message: json['message']?.toString() ?? '',
      link: json['link']?.toString(),
      createdAt: json['created_at']?.toString() ?? '',
      isRead: json['is_read'] == true,
    );
  }
}

class NotificationsNotifier extends ChangeNotifier {
  int unreadCount = 0;
  List<AppNotification> items = [];

  Map<String, String> _authHeaders(Map<String, dynamic> user) {
    final token = user['token']?.toString();
    final base = {
      'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
      'ngrok-skip-browser-warning': 'true',
    };
    return (token != null && token.isNotEmpty) ? {...base, 'Authorization': 'Bearer $token'} : base;
  }

  /// Lightweight badge refresh — mark=0 so opening other screens doesn't
  /// silently clear notifications the user hasn't actually seen yet.
  Future<void> poll(String baseUrl, Map<String, dynamic> user) async {
    try {
      final url = Uri.parse('$baseUrl/notifications.php?user_id=${user['id']}&mark=0');
      final res = await http.get(url, headers: _authHeaders(user)).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true) {
          unreadCount = (data['data']['unread_count'] ?? 0) as int;
          notifyListeners();
        } else {
          debugPrint('[Notifications.poll] API returned success=false: ${data['message'] ?? data}');
        }
      } else {
        // Most common cause: 401 = the stored token is no longer valid
        // (e.g. this account logged in elsewhere — tokens are single-
        // active-per-user — or the 30-day token expired).
        debugPrint('[Notifications.poll] HTTP ${res.statusCode}: ${res.body}');
      }
    } catch (e) {
      // Badge refresh still shouldn't interrupt the user, but log it so
      // a silently-empty badge is diagnosable instead of invisible.
      debugPrint('[Notifications.poll] failed: $e');
    }
  }

  /// Full list fetch for the notifications screen. mark=1 (the default
  /// on the endpoint) clears the badge, same as opening the web modal.
  Future<void> fetchList(String baseUrl, Map<String, dynamic> user) async {
    try {
      final url = Uri.parse('$baseUrl/notifications.php?user_id=${user['id']}');
      final res = await http.get(url, headers: _authHeaders(user)).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true) {
          final list = (data['data']['items'] as List<dynamic>? ?? [])
              .map((e) => AppNotification.fromJson(e as Map<String, dynamic>))
              .toList();
          items = list;
          unreadCount = 0;
          notifyListeners();
        } else {
          debugPrint('[Notifications.fetchList] API returned success=false: ${data['message'] ?? data}');
        }
      } else {
        debugPrint('[Notifications.fetchList] HTTP ${res.statusCode}: ${res.body}');
      }
    } catch (e) {
      // Leave whatever was already loaded in place on failure, but log it.
      debugPrint('[Notifications.fetchList] failed: $e');
    }
  }
}

final globalNotifications = NotificationsNotifier();

// ---------------------------------------------------------
// In-App Auto-Update Manager (One-Click App Updates)
// ---------------------------------------------------------
class AppUpdateChecker {
  static const int currentVersionCode = 12;
  static const String currentVersionName = '1.2.7';
  static const MethodChannel _channel = MethodChannel('com.duarte.duarte_app/updater');

  static bool _hasPromptedThisSession = false;

  static Future<Map<String, dynamic>?> checkForUpdate({bool silent = true}) async {
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final uri = Uri.parse('$baseUrl/version.php');
      final res = await http.get(uri).timeout(const Duration(seconds: 6));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        return data;
      }
    } catch (e) {
      debugPrint('[AppUpdateChecker] Check failed: $e');
    }
    return null;
  }

  static Future<void> checkAndShowPrompt(BuildContext context, {bool manual = false}) async {
    if (!manual && _hasPromptedThisSession) return;

    final info = await checkForUpdate(silent: !manual);
    if (!context.mounted) return;

    if (info == null) {
      if (manual) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(globalLanguage.isTagalog ? 'Hindi masuri ang update sa ngayon.' : 'Unable to check for updates right now.'),
          ),
        );
      }
      return;
    }

    final latestCode = (info['latest_version_code'] as num?)?.toInt() ?? 1;
    final latestName = (info['latest_version_name'] ?? '1.0.0').toString();
    final apkUrl = (info['apk_url'] ?? '${AppConfig.ngrokUrl}/../duarte-app.apk').toString();
    final notes = (info['release_notes'] ?? '').toString();
    final force = info['force_update'] == true;

    if (latestCode > currentVersionCode) {
      _hasPromptedThisSession = true;
      if (!context.mounted) return;
      showDialog(
        context: context,
        barrierDismissible: !force,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(color: AppColors.amberTint, borderRadius: BorderRadius.circular(10)),
                child: const Icon(Icons.system_update_rounded, color: AppColors.amber, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  globalLanguage.isTagalog ? 'May Bagong Update!' : 'Update Available!',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17, color: AppColors.ink),
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'v$latestName (${globalLanguage.isTagalog ? 'Kasalukuyan' : 'Current'}: v$currentVersionName)',
                style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.amber, fontSize: 13),
              ),
              const SizedBox(height: 10),
              if (notes.isNotEmpty) ...[
                Text(
                  globalLanguage.isTagalog ? 'Mga Pagbabago:' : 'What\'s New:',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.inkSoft),
                ),
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppColors.paper,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Text(notes, style: const TextStyle(fontSize: 12.5, height: 1.4, color: AppColors.ink)),
                ),
                const SizedBox(height: 12),
              ],
              Text(
                globalLanguage.isTagalog
                    ? 'I-click ang button sa ibaba para i-download at i-install ang pinakabagong bersyon.'
                    : 'Click the button below to download and install the latest version.',
                style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
              ),
            ],
          ),
          actions: [
            if (!force)
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(globalLanguage.isTagalog ? 'Mamaya Na' : 'Later', style: const TextStyle(color: AppColors.inkSoft)),
              ),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.amber,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              ),
              onPressed: () async {
                Navigator.pop(ctx);
                try {
                  await _channel.invokeMethod('openUrl', {'url': apkUrl});
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          globalLanguage.isTagalog
                              ? 'Nagsisimula ang download... Pindutin ang na-download na file para i-install.'
                              : 'Download starting... Tap the downloaded file to install.',
                        ),
                        backgroundColor: AppColors.greenOk,
                        duration: const Duration(seconds: 6),
                      ),
                    );
                  }
                } catch (e) {
                  debugPrint('Launch failed: $e');
                }
              },
              icon: const Icon(Icons.download_rounded, size: 18),
              label: Text(globalLanguage.isTagalog ? 'I-update Ngayon' : 'Update Now'),
            ),
          ],
        ),
      );
    } else if (manual) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            globalLanguage.isTagalog
                ? 'Nasa pinakabagong bersyon ka na! (v$currentVersionName)'
                : 'You are using the latest version! (v$currentVersionName)',
          ),
          backgroundColor: AppColors.greenOk,
        ),
      );
    }
  }
}

// ---------------------------------------------------------
// App Configuration & State Manager
// ---------------------------------------------------------
class AppConfig {
  static const String ngrokUrl = 'https://spruce-trapdoor-unsorted.ngrok-free.dev/duarte/api';
  static const String cloudflareUrl = ngrokUrl;
  static const String defaultUrl = ngrokUrl;
  static const String emulatorUrl = 'http://10.0.2.2/duarte/api';
  static const String localUrl = 'http://localhost/duarte/api';

  static Future<String> getBaseUrl() async {
    final prefs = await SharedPreferences.getInstance();
    final stored = prefs.getString('base_url');
    if (stored != null && (stored.contains('infinityfree') ||
        stored.contains('epizy') ||
        stored.contains('site.je') ||
        stored.contains('YOUR-SUBDOMAIN') ||
        stored.contains('marital-dividing-popcorn') ||
        stored.contains('outshine-aroma-angles') ||
        stored.contains('trycloudflare') ||
        stored.contains('swim-surveillance') ||
        stored.contains('onrender.com') ||
        stored.contains('duarte.onrender') ||
        (stored.contains('ngrok') && !stored.contains('spruce-trapdoor-unsorted')))) {
      await prefs.setString('base_url', defaultUrl);
      return defaultUrl;
    }
    return stored ?? defaultUrl;
  }

  static Future<void> setBaseUrl(String url) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('base_url', url.trim());
  }

  static Future<Map<String, dynamic>?> getUser() async {
    final prefs = await SharedPreferences.getInstance();
    final data = prefs.getString('user_session');
    if (data != null) {
      try {
        return jsonDecode(data) as Map<String, dynamic>;
      } catch (_) {}
    }
    return null;
  }

  static Future<List<Map<String, dynamic>>> getRememberedProfiles() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('remembered_profiles');
    if (raw == null || raw.trim().isEmpty) return [];
    try {
      final list = jsonDecode(raw) as List;
      return list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } catch (_) {
      return [];
    }
  }

  static Future<void> saveRememberedProfile(Map<String, dynamic> user, {bool? hasPin}) async {
    final prefs = await SharedPreferences.getInstance();
    final profiles = await getRememberedProfiles();
    final id = user['id'];
    if (id == null) return;

    final existingIndex = profiles.indexWhere((p) => p['id'].toString() == id.toString());
    final entry = {
      'id': id,
      'username': user['username'] ?? '',
      'full_name': user['full_name'] ?? '',
      'role': user['role'] ?? '',
      'position': user['position'] ?? '',
      'has_pin': (hasPin ?? user['has_pin']) == true || (hasPin ?? user['has_pin']) == 1 || (hasPin ?? user['has_pin']) == '1',
      'last_used': DateTime.now().millisecondsSinceEpoch,
    };

    if (existingIndex >= 0) {
      profiles[existingIndex] = entry;
    } else {
      profiles.add(entry);
    }

    await prefs.setString('remembered_profiles', jsonEncode(profiles));
    await prefs.setInt('last_profile_id', id is int ? id : int.tryParse(id.toString()) ?? 0);
  }

  static Future<void> removeRememberedProfile(dynamic userId) async {
    final prefs = await SharedPreferences.getInstance();
    final profiles = await getRememberedProfiles();
    profiles.removeWhere((p) => p['id'].toString() == userId.toString());
    await prefs.setString('remembered_profiles', jsonEncode(profiles));
  }

  static Future<int?> getLastProfileId() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getInt('last_profile_id');
  }

  static Future<void> setUser(Map<String, dynamic>? user) async {
    final prefs = await SharedPreferences.getInstance();
    if (user == null) {
      await prefs.remove('user_session');
    } else {
      await prefs.setString('user_session', jsonEncode(user));
    }
  }

  static Map<String, String> authHeaders(Map<String, dynamic>? user, {bool isJson = true}) {
    final token = user?['token']?.toString();
    final headers = <String, String>{
      'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
      'ngrok-skip-browser-warning': 'true',
    };
    if (isJson) {
      headers['Content-Type'] = 'application/json';
    }
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    return headers;
  }

  static String? resolveImageUrl(String? rawUrl, {String? filename, String? activeBaseUrl}) {
    final effectiveBase = (activeBaseUrl != null && activeBaseUrl.trim().isNotEmpty) ? activeBaseUrl.trim() : defaultUrl;

    if (rawUrl != null && rawUrl.trim().isNotEmpty) {
      try {
        final parsedUrl = Uri.parse(rawUrl.trim());
        final parsedBase = Uri.parse(effectiveBase);
        if ((parsedUrl.host == 'localhost' || parsedUrl.host == '127.0.0.1') &&
            parsedBase.host != 'localhost' &&
            parsedBase.host != '127.0.0.1') {
          return parsedUrl.replace(
            scheme: parsedBase.scheme,
            host: parsedBase.host,
            port: parsedBase.hasPort ? parsedBase.port : null,
          ).toString();
        }
        return rawUrl.trim();
      } catch (_) {
        return rawUrl.trim();
      }
    }

    if (filename != null && filename.trim().isNotEmpty) {
      try {
        final parsedBase = Uri.parse(effectiveBase);
        final basePath = parsedBase.path.replaceAll(RegExp(r'/api/?$'), '');
        final portPart = parsedBase.hasPort ? ':${parsedBase.port}' : '';
        return '${parsedBase.scheme}://${parsedBase.host}$portPart$basePath/uploads/items/${filename.trim()}';
      } catch (_) {}
    }

    return null;
  }

  static Future<void> cacheCatalog(List<dynamic> items, List<dynamic> trucks) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('cached_catalog', jsonEncode(items));
    await prefs.setString('cached_trucks', jsonEncode(trucks));
  }

  static Future<Map<String, List<dynamic>>> getCachedCatalog() async {
    final prefs = await SharedPreferences.getInstance();
    final itemsStr = prefs.getString('cached_catalog');
    final trucksStr = prefs.getString('cached_trucks');
    List<dynamic> items = [];
    List<dynamic> trucks = [];
    if (itemsStr != null) items = jsonDecode(itemsStr);
    if (trucksStr != null) trucks = jsonDecode(trucksStr);
    return {'items': items, 'trucks': trucks};
  }

  static Future<void> queueOfflineRequisition(Map<String, dynamic> req) async {
    final prefs = await SharedPreferences.getInstance();
    List<dynamic> queue = [];
    final queueStr = prefs.getString('offline_queue');
    if (queueStr != null) {
      queue = jsonDecode(queueStr);
    }
    req['offline_id'] = DateTime.now().millisecondsSinceEpoch.toString();
    req['created_offline_at'] = DateTime.now().toIso8601String();
    queue.add(req);
    await prefs.setString('offline_queue', jsonEncode(queue));
  }

  static Future<List<dynamic>> getOfflineQueue() async {
    final prefs = await SharedPreferences.getInstance();
    final queueStr = prefs.getString('offline_queue');
    if (queueStr != null) {
      return jsonDecode(queueStr);
    }
    return [];
  }

  static Future<void> clearOfflineQueue() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('offline_queue');
  }

  static Future<void> saveOfflineQueue(List<dynamic> queue) async {
    final prefs = await SharedPreferences.getInstance();
    if (queue.isEmpty) {
      await prefs.remove('offline_queue');
    } else {
      await prefs.setString('offline_queue', jsonEncode(queue));
    }
  }
}

// ---------------------------------------------------------
// Main Root Application
// ---------------------------------------------------------
// ---------------------------------------------------------
// Reusable Network Item Image with fallback & loader
// ---------------------------------------------------------
class AppItemImage extends StatelessWidget {
  final String? imageUrl;
  final double? width;
  final double? height;
  final BorderRadius? borderRadius;
  final IconData fallbackIcon;
  final double? iconSize;
  final BoxFit fit;

  const AppItemImage({
    super.key,
    required this.imageUrl,
    this.width = 44,
    this.height = 44,
    this.borderRadius,
    this.fallbackIcon = Icons.build_circle_outlined,
    this.iconSize,
    this.fit = BoxFit.cover,
  });

  @override
  Widget build(BuildContext context) {
    final radius = borderRadius ?? BorderRadius.circular(7);
    final isUrlValid = imageUrl != null && imageUrl!.trim().isNotEmpty;
    final calcIconSize = iconSize ??
        ((height != null && height!.isFinite)
            ? (height! * 0.42).clamp(16.0, 48.0)
            : ((width != null && width!.isFinite)
                ? (width! * 0.42).clamp(16.0, 48.0)
                : 28.0));

    Widget fallback() => Container(
          width: width,
          height: height,
          decoration: BoxDecoration(
            color: AppColors.surfaceSubtle,
            borderRadius: radius,
            border: Border.all(color: AppColors.line),
          ),
          child: Center(
            child: Icon(
              fallbackIcon,
              color: AppColors.amber,
              size: calcIconSize,
            ),
          ),
        );

    if (!isUrlValid) {
      return fallback();
    }

    return ClipRRect(
      borderRadius: radius,
      child: Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: AppColors.surfaceSubtle,
          borderRadius: radius,
          border: Border.all(color: AppColors.line),
        ),
        child: Image.network(
          imageUrl!.trim(),
          headers: const {
            'ngrok-skip-browser-warning': 'true',
            'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
          },
          width: width,
          height: height,
          fit: fit,
          loadingBuilder: (context, child, loadingProgress) {
            if (loadingProgress == null) return child;
            final loaderSize = (height != null && height!.isFinite)
                ? (height! * 0.25).clamp(16.0, 32.0)
                : 22.0;
            return Center(
              child: SizedBox(
                width: loaderSize,
                height: loaderSize,
                child: const CircularProgressIndicator(
                  strokeWidth: 2,
                  color: AppColors.amber,
                ),
              ),
            );
          },
          errorBuilder: (context, error, stackTrace) => fallback(),
        ),
      ),
    );
  }
}

class DuarteApp extends StatelessWidget {
  const DuarteApp({super.key});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: globalLanguage,
      builder: (context, _) {
        return MaterialApp(
          title: 'DuaRTE',
          debugShowCheckedModeBanner: false,
          theme: ThemeData(
            useMaterial3: true,
            scaffoldBackgroundColor: AppColors.paper,
            colorScheme: ColorScheme.fromSeed(
              seedColor: AppColors.amber,
              primary: AppColors.amber,
              surface: AppColors.surface,
            ),
            pageTransitionsTheme: const PageTransitionsTheme(
              builders: {
                TargetPlatform.android: ZoomPageTransitionsBuilder(),
                TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
              },
            ),
            appBarTheme: const AppBarTheme(
              backgroundColor: AppColors.charcoal2,
              foregroundColor: Colors.white,
              elevation: 0,
              scrolledUnderElevation: 2,
            ),
            cardTheme: CardTheme(
              color: AppColors.surface,
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: AppColors.line, width: 1),
              ),
            ),
            inputDecorationTheme: InputDecorationTheme(
              filled: true,
              fillColor: AppColors.surface,
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(8),
                borderSide: const BorderSide(color: AppColors.line),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(8),
                borderSide: const BorderSide(color: AppColors.line),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(8),
                borderSide: const BorderSide(color: AppColors.amber, width: 1.8),
              ),
            ),
            elevatedButtonTheme: ElevatedButtonThemeData(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.amber,
                foregroundColor: Colors.white,
                elevation: 0,
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
                textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
              ),
            ),
          ),
          home: const SplashScreen(),
        );
      },
    );
  }
}

// ---------------------------------------------------------
// Shared DuaRTE Web-Style App Bar
// ---------------------------------------------------------
PreferredSizeWidget buildWebStyleAppBar({
  required BuildContext context,
  required String activeTitle,
  required Map<String, dynamic> user,
  List<Widget>? actions,
  bool showBackButton = false,
}) {
  return AppBar(
    automaticallyImplyLeading: showBackButton,
    leading: showBackButton
        ? IconButton(
            icon: const Icon(Icons.arrow_back, color: Colors.white),
            onPressed: () => Navigator.of(context).maybePop(),
          )
        : null,
    backgroundColor: AppColors.charcoal2,
    titleSpacing: showBackButton ? 0 : 14,
    title: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Image.asset(
          'assets/images/logo.png',
          height: 20,
          fit: BoxFit.contain,
          errorBuilder: (_, __, ___) => const SizedBox.shrink(),
        ),
        const SizedBox(width: 7),
        const Text(
          'DuaRTE',
          style: TextStyle(
            color: AppColors.amberOnDark,
            fontWeight: FontWeight.bold,
            fontSize: 16.5,
            letterSpacing: 0.6,
          ),
        ),
        const SizedBox(width: 6),
        Flexible(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
            decoration: BoxDecoration(
              color: AppColors.charcoalHover,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(color: AppColors.charcoalBorder),
            ),
            child: Text(
              activeTitle.toUpperCase(),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: Colors.white70,
                fontSize: 9.5,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.3,
              ),
            ),
          ),
        ),
      ],
    ),
    actions: [
      // Quick Language Toggle Chip in App Bar
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 2),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () => globalLanguage.toggle(),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
            decoration: BoxDecoration(
              color: AppColors.charcoalHover,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.charcoalBorder),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  globalLanguage.isTagalog ? '🇵🇭 TL' : '🇺🇸 EN',
                  style: const TextStyle(
                    color: AppColors.amberOnDark,
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(width: 2),
                const Icon(Icons.swap_horiz, size: 13, color: AppColors.amberOnDark),
              ],
            ),
          ),
        ),
      ),
      if (actions != null) ...actions,
      AnimatedBuilder(
        animation: globalNotifications,
        builder: (context, _) {
          final count = globalNotifications.unreadCount;
          return IconButton(
            tooltip: globalLanguage.isTagalog ? 'Mga Notification' : 'Notifications',
            padding: const EdgeInsets.symmetric(horizontal: 4),
            constraints: const BoxConstraints(),
            icon: Badge(
              isLabelVisible: count > 0,
              label: Text(count > 99 ? '99+' : '$count'),
              backgroundColor: AppColors.amber,
              child: const Icon(Icons.notifications_outlined, color: Colors.white70, size: 22),
            ),
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => NotificationsScreen(user: user)),
              );
            },
          );
        },
      ),
      const SizedBox(width: 4),
      Padding(
        padding: const EdgeInsets.only(right: 12),
        child: CircleAvatar(
          radius: 14,
          backgroundColor: AppColors.amber,
          child: Text(
            (user['full_name'] ?? user['username'] ?? 'U')[0].toUpperCase(),
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
          ),
        ),
      ),
    ],
  );
}

// ---------------------------------------------------------
// Notifications Screen — list backing the bell icon's badge.
// ---------------------------------------------------------
class NotificationsScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const NotificationsScreen({super.key, required this.user});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final baseUrl = await AppConfig.getBaseUrl();
    await globalNotifications.fetchList(baseUrl, widget.user);
    if (mounted) setState(() => _loading = false);
  }

  String _timeAgo(String createdAt) {
    final dt = DateTime.tryParse(createdAt);
    if (dt == null) return createdAt;
    final diff = DateTime.now().difference(dt);
    if (diff.inMinutes < 1) return globalLanguage.isTagalog ? 'Ngayon lang' : 'Just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    return '${diff.inDays}d ago';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface,
      appBar: AppBar(
        backgroundColor: AppColors.charcoal2,
        title: Text(globalLanguage.isTagalog ? 'Mga Notification' : 'Notifications', style: const TextStyle(color: Colors.white, fontSize: 16)),
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: AnimatedBuilder(
        animation: globalNotifications,
        builder: (context, _) {
          final items = globalNotifications.items;
          if (_loading) {
            return const Center(child: CircularProgressIndicator(color: AppColors.amber));
          }
          if (items.isEmpty) {
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.notifications_none_rounded, size: 48, color: AppColors.inkSoft),
                  const SizedBox(height: 12),
                  Text(globalLanguage.isTagalog ? 'Wala pang notification.' : 'No notifications yet.', style: const TextStyle(color: AppColors.inkSoft)),
                ],
              ),
            );
          }
          return RefreshIndicator(
            color: AppColors.amber,
            onRefresh: _load,
            child: ListView.separated(
              padding: const EdgeInsets.all(12),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final n = items[i];
                return Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: n.isRead ? AppColors.surface : AppColors.amberTint,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.notifications_active_outlined,
                        color: n.isRead ? AppColors.inkSoft : AppColors.amber,
                        size: 20,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(n.message, style: const TextStyle(color: AppColors.ink, fontSize: 13.5)),
                            const SizedBox(height: 4),
                            Text(_timeAgo(n.createdAt),
                                style: const TextStyle(color: AppColors.inkSoft, fontSize: 11)),
                          ],
                        ),
                      ),
                    ],
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------
// Splash Screen
// ---------------------------------------------------------
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _checkAuth();
  }

  Future<void> _checkAuth() async {
    await Future.delayed(const Duration(milliseconds: 500));
    final user = await AppConfig.getUser();
    if (!mounted) return;
    if (user != null) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => MainNavigationScreen(user: user)),
      );
      return;
    }

    final profiles = await AppConfig.getRememberedProfiles();
    if (!mounted) return;
    if (profiles.isNotEmpty) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const PinLoginScreen()),
      );
    } else {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.charcoal,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 80,
              height: 80,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: AppColors.charcoalBorder),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.25),
                    blurRadius: 16,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Image.asset(
                'assets/images/logo.png',
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Icon(
                  Icons.inventory_2_rounded,
                  size: 48,
                  color: AppColors.amber,
                ),
              ),
            ),
            const SizedBox(height: 20),
            const Text(
              'DuaRTE',
              style: TextStyle(
                fontSize: 28,
                fontWeight: FontWeight.bold,
                color: AppColors.amberOnDark,
                letterSpacing: 1.5,
              ),
            ),
            const SizedBox(height: 6),
            const Text(
              'Heavy Fleet & Warehouse Logistics',
              style: TextStyle(fontSize: 13, color: AppColors.inkLight),
            ),
            const SizedBox(height: 36),
            const CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.amberOnDark),
          ],
        ),
      ),
    );
  }
}


// ---------------------------------------------------------
// 4-Digit PIN Setup Dialog (Hardened Anti-Spam & Verification)
// ---------------------------------------------------------
class PinSetupDialog extends StatefulWidget {
  final String token;
  final bool hasExistingPin;
  const PinSetupDialog({super.key, required this.token, this.hasExistingPin = false});

  @override
  State<PinSetupDialog> createState() => _PinSetupDialogState();
}

class _PinSetupDialogState extends State<PinSetupDialog> {
  late int _step; // 0: Current PIN (if existing), 1: New PIN, 2: Confirm New PIN
  String _oldPin = '';
  String _newPin = '';
  String _currentInput = '';
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _step = widget.hasExistingPin ? 0 : 1;
  }

  void _onKeyTap(String digit) {
    if (_isLoading) return;
    if (_currentInput.length < 4) {
      setState(() {
        _currentInput += digit;
        _errorMessage = null;
      });
      if (_currentInput.length == 4) {
        _handleComplete();
      }
    }
  }

  void _onBackspace() {
    if (_isLoading || _currentInput.isEmpty) return;
    setState(() {
      _currentInput = _currentInput.substring(0, _currentInput.length - 1);
      _errorMessage = null;
    });
  }

  void _onClear() {
    if (_isLoading) return;
    setState(() {
      _currentInput = '';
      _errorMessage = null;
    });
  }

  Future<void> _handleComplete() async {
    if (_step == 0) {
      // Finished entering old PIN
      setState(() {
        _oldPin = _currentInput;
        _currentInput = '';
        _step = 1;
      });
    } else if (_step == 1) {
      // Finished entering new PIN
      if (widget.hasExistingPin && _currentInput == _oldPin) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog
              ? 'Ang bagong PIN ay hindi maaaring pareho sa lumang PIN.'
              : 'New PIN cannot be the same as old PIN.';
          _currentInput = '';
        });
        return;
      }
      setState(() {
        _newPin = _currentInput;
        _currentInput = '';
        _step = 2;
      });
    } else {
      // Finished confirming new PIN
      if (_currentInput != _newPin) {
        setState(() {
          _errorMessage = globalLanguage.t('pin_mismatch');
          _currentInput = '';
          _newPin = '';
          _step = widget.hasExistingPin ? 0 : 1;
        });
        return;
      }

      setState(() => _isLoading = true);
      try {
        final baseUrl = await AppConfig.getBaseUrl();
        final user = await AppConfig.getUser();
        final effectiveToken = widget.token.isNotEmpty ? widget.token : (user?['token']?.toString() ?? '');
        final payload = <String, dynamic>{
          'pin': _newPin,
          'token': effectiveToken,
        };
        if (widget.hasExistingPin) {
          payload['current_pin'] = _oldPin;
        }

        final res = await http.post(
          Uri.parse('$baseUrl/pin_setup.php'),
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer $effectiveToken',
            'ngrok-skip-browser-warning': 'true',
            'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
          },
          body: jsonEncode(payload),
        ).timeout(const Duration(seconds: 8));

        final data = jsonDecode(res.body);
        if (res.statusCode == 200 && data['success'] == true) {
          if (user != null) {
            user['has_pin'] = true;
            await AppConfig.setUser(user);
            await AppConfig.saveRememberedProfile(user, hasPin: true);
          }
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(data['data']?['message'] ?? data['message'] ?? globalLanguage.t('pin_saved'))),
            );
            Navigator.pop(context, true);
          }
        } else {
          setState(() {
            _errorMessage = data['error'] ?? data['message'] ?? (globalLanguage.isTagalog ? 'Hindi nai-save ang PIN.' : 'Failed to save PIN.');
            _currentInput = '';
            _newPin = '';
            _step = widget.hasExistingPin ? 0 : 1;
          });
        }
      } catch (_) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog
              ? 'Hindi makakonekta sa server. Pakisuri ang internet connection.'
              : 'Unable to connect to server. Please check your network connection.';
          _currentInput = '';
          _newPin = '';
          _step = widget.hasExistingPin ? 0 : 1;
        });
      } finally {
        if (mounted) setState(() => _isLoading = false);
      }
    }
  }

  String _getStepTitle() {
    if (_step == 0) {
      return globalLanguage.isTagalog ? 'Kasalukuyang PIN' : 'Current PIN';
    }
    if (_step == 1) {
      return widget.hasExistingPin
          ? (globalLanguage.isTagalog ? 'Bagong 4-Digit PIN' : 'New 4-Digit PIN')
          : globalLanguage.t('pin_setup_title');
    }
    return globalLanguage.t('pin_confirm');
  }

  String _getStepDescription() {
    if (_step == 0) {
      return globalLanguage.isTagalog
          ? 'Ilagay ang iyong lumang PIN bago mag-set ng bago:'
          : 'Enter your current PIN before setting a new one:';
    }
    if (_step == 1) {
      return widget.hasExistingPin
          ? (globalLanguage.isTagalog
              ? 'Ilagay ang iyong bagong 4-digit security PIN:'
              : 'Enter your new 4-digit security PIN:')
          : globalLanguage.t('pin_setup_desc');
    }
    return globalLanguage.isTagalog
        ? 'Ipasok muli ang bagong 4 na numero upang kumpirmahin:'
        : 'Re-enter the new 4 digits to confirm your PIN:';
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Container(
        constraints: const BoxConstraints(maxWidth: 360),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 22),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    const Icon(Icons.shield_outlined, color: AppColors.amber, size: 22),
                    const SizedBox(width: 8),
                    Text(
                      _getStepTitle(),
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.ink),
                    ),
                  ],
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 20, color: AppColors.inkSoft),
                  onPressed: () => Navigator.pop(context, false),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              _getStepDescription(),
              style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 18),

            // 4 Indicator Dots
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(4, (i) {
                final isFilled = i < _currentInput.length;
                return Container(
                  margin: const EdgeInsets.symmetric(horizontal: 8),
                  width: 16,
                  height: 16,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: isFilled ? AppColors.amber : Colors.transparent,
                    border: Border.all(
                      color: isFilled ? AppColors.amber : AppColors.lineStrong,
                      width: 2,
                    ),
                  ),
                );
              }),
            ),

            if (_errorMessage != null) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.redTint,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: AppColors.redBorder),
                ),
                child: Text(
                  _errorMessage!,
                  style: const TextStyle(color: AppColors.redDanger, fontSize: 11.5, fontWeight: FontWeight.w600),
                  textAlign: TextAlign.center,
                ),
              ),
            ],

            if (_isLoading) ...[
              const SizedBox(height: 16),
              const CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.amber),
            ] else ...[
              const SizedBox(height: 18),
              _buildKeypad(),
            ],

            const SizedBox(height: 12),
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(
                globalLanguage.t('pin_skip'),
                style: const TextStyle(color: AppColors.inkSoft, fontSize: 13),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildKeypad() {
    return Column(
      children: [
        for (var row in [
          ['1', '2', '3'],
          ['4', '5', '6'],
          ['7', '8', '9'],
          ['C', '0', '⌫'],
        ])
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              children: row.map((k) {
                final isAction = k == 'C' || k == '⌫';
                return SizedBox(
                  width: 68,
                  height: 52,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: isAction ? AppColors.surfaceSubtle : AppColors.surface,
                      foregroundColor: isAction ? AppColors.inkSoft : AppColors.ink,
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10),
                        side: const BorderSide(color: AppColors.line),
                      ),
                      padding: EdgeInsets.zero,
                    ),
                    onPressed: () {
                      HapticFeedback.lightImpact();
                      if (k == 'C') {
                        _onClear();
                      } else if (k == '⌫') {
                        _onBackspace();
                      } else {
                        _onKeyTap(k);
                      }
                    },
                    child: k == '⌫'
                        ? const Icon(Icons.backspace_outlined, size: 20)
                        : Text(
                            k,
                            style: TextStyle(
                              fontSize: isAction ? 14 : 20,
                              fontWeight: isAction ? FontWeight.bold : FontWeight.w600,
                            ),
                          ),
                  ),
                );
              }).toList(),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------
// 4-Digit PIN Login Screen (Fast 1-Second Field Access)
// ---------------------------------------------------------
class PinLoginScreen extends StatefulWidget {
  final Map<String, dynamic>? initialProfile;
  const PinLoginScreen({super.key, this.initialProfile});

  @override
  State<PinLoginScreen> createState() => _PinLoginScreenState();
}

class _PinLoginScreenState extends State<PinLoginScreen> {
  List<Map<String, dynamic>> _profiles = [];
  Map<String, dynamic>? _activeProfile;
  String _pin = '';
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadProfiles();
  }

  Future<void> _loadProfiles() async {
    final list = await AppConfig.getRememberedProfiles();
    if (!mounted) return;
    if (list.isEmpty) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
      return;
    }

    final lastId = await AppConfig.getLastProfileId();
    Map<String, dynamic>? match;
    if (widget.initialProfile != null) {
      match = list.firstWhere(
        (p) => p['id'].toString() == widget.initialProfile!['id'].toString(),
        orElse: () => list.first,
      );
    } else if (lastId != null) {
      match = list.firstWhere(
        (p) => p['id'].toString() == lastId.toString(),
        orElse: () => list.first,
      );
    } else {
      match = list.first;
    }

    setState(() {
      _profiles = list;
      _activeProfile = match;
    });
  }

  void _onKeyTap(String val) {
    if (_isLoading || _activeProfile == null) return;
    if (_pin.length < 4) {
      setState(() {
        _pin += val;
        _errorMessage = null;
      });
      if (_pin.length == 4) {
        _submitPin();
      }
    }
  }

  void _onBackspace() {
    if (_isLoading || _pin.isEmpty) return;
    setState(() {
      _pin = _pin.substring(0, _pin.length - 1);
      _errorMessage = null;
    });
  }

  void _onClear() {
    if (_isLoading) return;
    setState(() {
      _pin = '';
      _errorMessage = null;
    });
  }

  Future<void> _submitPin() async {
    if (_activeProfile == null) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final res = await http.post(
        Uri.parse('$baseUrl/pin_login.php'),
        headers: {
          'Content-Type': 'application/json',
          'ngrok-skip-browser-warning': 'true',
          'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
        },
        body: jsonEncode({
          'user_id': _activeProfile!['id'],
          'pin': _pin,
        }),
      ).timeout(const Duration(seconds: 8));

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        final payload = data['data'] ?? data;
        final userData = (payload['user'] ?? {}) as Map<String, dynamic>;
        if (payload['token'] != null) {
          userData['token'] = payload['token'];
        }
        await AppConfig.setUser(userData);
        await AppConfig.saveRememberedProfile(userData, hasPin: true);

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => MainNavigationScreen(user: userData)),
        );
      } else {
        setState(() {
          _pin = '';
          _errorMessage = data['error'] ?? data['message'] ?? (globalLanguage.isTagalog ? 'Maling PIN. Pakisubukan muli.' : 'Invalid PIN. Please try again.');
        });
      }
    } catch (_) {
      setState(() {
        _pin = '';
        _errorMessage = globalLanguage.t('login_server_error');
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _switchToPasswordLogin() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final active = _activeProfile;
    final hasPin = active != null && (active['has_pin'] == true || active['has_pin'] == 1 || active['has_pin'] == '1');

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 380),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Top bar with language & brand
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(6),
                            decoration: BoxDecoration(
                              color: AppColors.charcoal2,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: const Icon(Icons.local_shipping_rounded, color: AppColors.amberOnDark, size: 18),
                          ),
                          const SizedBox(width: 8),
                          const Text(
                            'DuaRTE',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17, color: AppColors.ink, letterSpacing: 0.5),
                          ),
                        ],
                      ),
                      const LanguageToggleBar(),
                    ],
                  ),

                  const SizedBox(height: 20),

                  // Horizontal Profile Switcher (For Single-Device Multi-Account Testing & Defense)
                  if (_profiles.length > 1) ...[
                    Container(
                      margin: const EdgeInsets.only(bottom: 16),
                      child: SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: [
                            for (var p in _profiles) ...[
                              Padding(
                                padding: const EdgeInsets.only(right: 8),
                                child: ChoiceChip(
                                  selectedColor: AppColors.amberTint,
                                  backgroundColor: AppColors.surface,
                                  side: BorderSide(
                                    color: (active != null && active['id'].toString() == p['id'].toString())
                                        ? AppColors.amber
                                        : AppColors.line,
                                    width: 1.5,
                                  ),
                                  label: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(
                                        p['role'] == 'driver_helper' ? Icons.local_shipping_rounded : Icons.person_rounded,
                                        size: 15,
                                        color: (active != null && active['id'].toString() == p['id'].toString())
                                            ? AppColors.amber
                                            : AppColors.inkSoft,
                                      ),
                                      const SizedBox(width: 6),
                                      Text(
                                        p['full_name'] ?? p['username'] ?? '',
                                        style: TextStyle(
                                          fontSize: 12,
                                          fontWeight: (active != null && active['id'].toString() == p['id'].toString())
                                              ? FontWeight.bold
                                              : FontWeight.normal,
                                          color: AppColors.ink,
                                        ),
                                      ),
                                    ],
                                  ),
                                  selected: active != null && active['id'].toString() == p['id'].toString(),
                                  onSelected: (sel) {
                                    if (sel) {
                                      setState(() {
                                        _activeProfile = p;
                                        _pin = '';
                                        _errorMessage = null;
                                      });
                                    }
                                  },
                                ),
                              ),
                            ],
                            ActionChip(
                              backgroundColor: AppColors.surfaceSubtle,
                              side: const BorderSide(color: AppColors.line),
                              avatar: const Icon(Icons.add, size: 16, color: AppColors.inkSoft),
                              label: Text(
                                globalLanguage.isTagalog ? 'Ibang Account' : 'New User',
                                style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                              ),
                              onPressed: _switchToPasswordLogin,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],

                  // Main PIN Card
                  Card(
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16),
                      side: const BorderSide(color: AppColors.line, width: 1.2),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                      child: Column(
                        children: [
                          // User Avatar & Role Badge
                          CircleAvatar(
                            radius: 34,
                            backgroundColor: AppColors.charcoal2,
                            child: Icon(
                              active?['role'] == 'driver_helper' ? Icons.local_shipping_rounded : Icons.badge_rounded,
                              size: 34,
                              color: AppColors.amberOnDark,
                            ),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            active?['full_name'] ?? active?['username'] ?? 'Personnel',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppColors.ink),
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                            decoration: BoxDecoration(
                              color: AppColors.amberTint,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.amberBorder),
                            ),
                            child: Text(
                              (active?['position'] != null && active!['position'].toString().isNotEmpty)
                                  ? active['position'].toString().toUpperCase()
                                  : (active?['role'] ?? 'USER').toString().toUpperCase(),
                              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.amber),
                            ),
                          ),
                          if (!hasPin) ...[
                            const SizedBox(height: 16),
                            Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: AppColors.surfaceSubtle,
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(color: AppColors.line),
                              ),
                              child: Column(
                                children: [
                                  const Icon(Icons.lock_open_rounded, color: AppColors.amber, size: 28),
                                  const SizedBox(height: 8),
                                  Text(
                                    globalLanguage.isTagalog
                                        ? 'Wala pang naka-set na 4-digit PIN ang account na ito.'
                                        : 'This account has no 4-digit PIN configured yet.',
                                    textAlign: TextAlign.center,
                                    style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 20),
                            SizedBox(
                              width: double.infinity,
                              child: ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: AppColors.amber,
                                  foregroundColor: Colors.white,
                                  padding: const EdgeInsets.symmetric(vertical: 12),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                ),
                                onPressed: _switchToPasswordLogin,
                                icon: const Icon(Icons.password_rounded, size: 18),
                                label: Text(
                                  globalLanguage.isTagalog ? 'Mag-login gamit ang Password' : 'Log In with Password',
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                ),
                              ),
                            ),
                          ] else ...[
                          Text(
                            globalLanguage.t('pin_enter'),
                            style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
                          ),
                          const SizedBox(height: 16),

                          // 4 PIN Dots Indicator
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: List.generate(4, (i) {
                              final isFilled = i < _pin.length;
                              return Container(
                                margin: const EdgeInsets.symmetric(horizontal: 10),
                                width: 18,
                                height: 18,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  color: isFilled ? AppColors.amber : Colors.transparent,
                                  border: Border.all(
                                    color: isFilled ? AppColors.amber : AppColors.lineStrong,
                                    width: 2.2,
                                  ),
                                ),
                              );
                            }),
                          ),

                          if (_errorMessage != null) ...[
                            const SizedBox(height: 14),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                              decoration: BoxDecoration(
                                color: AppColors.redTint,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: AppColors.redBorder),
                              ),
                              child: Text(
                                _errorMessage!,
                                style: const TextStyle(color: AppColors.redDanger, fontSize: 12, fontWeight: FontWeight.w600),
                                textAlign: TextAlign.center,
                              ),
                            ),
                          ],

                          if (_isLoading) ...[
                            const SizedBox(height: 24),
                            const CircularProgressIndicator(strokeWidth: 2.5, color: AppColors.amber),
                            const SizedBox(height: 16),
                          ] else ...[
                            const SizedBox(height: 22),
                            _buildKeypad(),
                          ],
                          ],
                        ],
                      ),
                    ),
                  ),

                  const SizedBox(height: 16),

                  // Bottom Action: Use Password / Switch Account
                  TextButton.icon(
                    onPressed: _switchToPasswordLogin,
                    icon: const Icon(Icons.password_rounded, size: 18, color: AppColors.amber),
                    label: Text(
                      globalLanguage.t('pin_switch_account'),
                      style: const TextStyle(color: AppColors.amber, fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildKeypad() {
    return Column(
      children: [
        for (var row in [
          ['1', '2', '3'],
          ['4', '5', '6'],
          ['7', '8', '9'],
          ['C', '0', '⌫'],
        ])
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              children: row.map((k) {
                final isAction = k == 'C' || k == '⌫';
                return SizedBox(
                  width: 76,
                  height: 54,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: isAction ? AppColors.surfaceSubtle : AppColors.surface,
                      foregroundColor: isAction ? AppColors.inkSoft : AppColors.ink,
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                        side: const BorderSide(color: AppColors.line),
                      ),
                      padding: EdgeInsets.zero,
                    ),
                    onPressed: () {
                      HapticFeedback.lightImpact();
                      if (k == 'C') {
                        _onClear();
                      } else if (k == '⌫') {
                        _onBackspace();
                      } else {
                        _onKeyTap(k);
                      }
                    },
                    child: k == '⌫'
                        ? const Icon(Icons.backspace_outlined, size: 22)
                        : Text(
                            k,
                            style: TextStyle(
                              fontSize: isAction ? 15 : 22,
                              fontWeight: isAction ? FontWeight.bold : FontWeight.w600,
                            ),
                          ),
                  ),
                );
              }).toList(),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------
// Login Screen (Exact Web Style Card Layout)
// ---------------------------------------------------------
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _isLoading = false;
  bool _obscurePassword = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppUpdateChecker.checkAndShowPrompt(context);
    });
  }

  Future<void> _handleLogin() async {
    final username = _usernameCtrl.text.trim();
    final password = _passwordCtrl.text.trim();

    if (username.isEmpty || password.isEmpty) {
      setState(() => _errorMessage = globalLanguage.t('login_empty_error'));
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/login.php');

      final response = await http.post(
        url,
        headers: {
          'Content-Type': 'application/json',
          'ngrok-skip-browser-warning': 'true',
          'User-Agent': 'Mozilla/5.0 (Linux; Android 10) DuaRTEApp/1.0',
        },
        body: jsonEncode({'username': username, 'password': password}),
      ).timeout(const Duration(seconds: 8));

      if (!response.body.trim().startsWith('{')) {
        setState(() {
          _errorMessage = globalLanguage.t('login_server_error');
        });
        return;
      }

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['success'] == true) {
        final payload = data['data'] ?? data;
        final userData = (payload['user'] ?? {}) as Map<String, dynamic>;
        if (payload['token'] != null) {
          userData['token'] = payload['token'];
        }
        await AppConfig.setUser(userData);
        final hasPin = userData['has_pin'] == true || userData['has_pin'] == 1 || userData['has_pin'] == '1';
        await AppConfig.saveRememberedProfile(userData, hasPin: hasPin);

        if (!mounted) return;

        // If user has not set a PIN yet, prompt them to set a 4-digit PIN!
        if (!hasPin) {
          final pinSet = await showDialog<bool>(
            context: context,
            barrierDismissible: false,
            builder: (_) => PinSetupDialog(token: userData['token']?.toString() ?? ''),
          );
          if (pinSet == true) {
            userData['has_pin'] = true;
            await AppConfig.setUser(userData);
            await AppConfig.saveRememberedProfile(userData, hasPin: true);
          }
        }

        if (!mounted) return;
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => MainNavigationScreen(user: userData)),
        );
      } else {
        setState(() {
          _errorMessage = data['error'] ?? data['message'] ?? (globalLanguage.isTagalog ? 'Maling username o password.' : 'Invalid username or password.');
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = globalLanguage.isTagalog
            ? 'Hindi makakonekta sa server. Pakisuri ang iyong koneksyon sa internet.'
            : 'Unable to connect to server. Please check your network connection.';
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showSettingsDialog() async {
    final curUrl = await AppConfig.getBaseUrl();
    final ctrl = TextEditingController(text: curUrl);

    if (!mounted) return;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(globalLanguage.t('server_settings'), style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.ink)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              globalLanguage.isTagalog ? 'Pumili ng connection profile o ilagay ang server address:' : 'Select connection profile or enter server address:',
              style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: ctrl,
              style: const TextStyle(fontSize: 13),
              decoration: const InputDecoration(
                hintText: 'https://...',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                ActionChip(
                  label: const Text('Online Server'),
                  onPressed: () => ctrl.text = AppConfig.ngrokUrl,
                ),
                ActionChip(
                  label: const Text('Local Network'),
                  onPressed: () => ctrl.text = AppConfig.localUrl,
                ),
              ],
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft))),
          ElevatedButton(
            onPressed: () async {
              await AppConfig.setBaseUrl(ctrl.text);
              if (ctx.mounted) Navigator.pop(ctx);
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(globalLanguage.t('server_saved'))));
              }
            },
            child: Text(globalLanguage.t('save')),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Prominent Language Selector at the top
                  Container(
                    margin: const EdgeInsets.only(bottom: 16),
                    child: Column(
                      children: [
                        Text(
                          globalLanguage.isTagalog ? 'Pumili ng Wika / Language' : 'Language Selection / Wika',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
                        ),
                        const SizedBox(height: 6),
                        const LanguageToggleBar(),
                      ],
                    ),
                  ),

                  // Main Login Card
                  Card(
                    color: AppColors.surface,
                    elevation: 1,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14),
                      side: const BorderSide(color: AppColors.line, width: 1.2),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 28),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Center(
                            child: Container(
                              width: 76,
                              height: 76,
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(16),
                                boxShadow: [
                                  BoxShadow(
                                    color: Colors.black.withOpacity(0.08),
                                    blurRadius: 12,
                                    offset: const Offset(0, 4),
                                  ),
                                ],
                                border: Border.all(color: AppColors.line),
                              ),
                              child: Image.asset(
                                'assets/images/logo.png',
                                fit: BoxFit.contain,
                                errorBuilder: (ctx, err, stack) => const Icon(
                                  Icons.local_shipping_rounded,
                                  size: 40,
                                  color: AppColors.amber,
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(height: 14),
                          const Center(
                            child: Text(
                              'DuaRTE',
                              style: TextStyle(
                                fontSize: 26,
                                fontWeight: FontWeight.bold,
                                color: AppColors.ink,
                                letterSpacing: 0.8,
                              ),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Center(
                            child: Text(
                              globalLanguage.t('app_subtitle'),
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
                            ),
                          ),
                          const SizedBox(height: 20),

                          if (_errorMessage != null)
                            Container(
                              margin: const EdgeInsets.only(bottom: 18),
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: AppColors.redTint,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: AppColors.redBorder),
                              ),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Icon(Icons.error_outline, color: AppColors.redDanger, size: 20),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(_errorMessage!, style: const TextStyle(color: AppColors.redDanger, fontSize: 13, height: 1.3)),
                                  ),
                                ],
                              ),
                            ),

                          // Username
                          Text(
                            globalLanguage.t('username'),
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: AppColors.ink),
                          ),
                          const SizedBox(height: 6),
                          TextField(
                            controller: _usernameCtrl,
                            style: const TextStyle(fontSize: 15),
                            decoration: InputDecoration(
                              hintText: globalLanguage.t('username_hint'),
                              prefixIcon: const Icon(Icons.person_outline, size: 20, color: AppColors.inkSoft),
                              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                            ),
                          ),
                          const SizedBox(height: 16),

                          // Password with Eye Icon
                          Text(
                            globalLanguage.t('password'),
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: AppColors.ink),
                          ),
                          const SizedBox(height: 6),
                          TextField(
                            controller: _passwordCtrl,
                            obscureText: _obscurePassword,
                            style: const TextStyle(fontSize: 15),
                            decoration: InputDecoration(
                              hintText: globalLanguage.t('password_hint'),
                              prefixIcon: const Icon(Icons.lock_outline, size: 20, color: AppColors.inkSoft),
                              suffixIcon: IconButton(
                                icon: Icon(
                                  _obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                                  color: AppColors.inkSoft,
                                  size: 20,
                                ),
                                tooltip: _obscurePassword ? globalLanguage.t('show_password') : globalLanguage.t('hide_password'),
                                onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                              ),
                              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                            ),
                          ),
                          const SizedBox(height: 24),

                          // Big 54px Login Button (Super Easy to Tap)
                          ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppColors.amber,
                              foregroundColor: Colors.white,
                              minimumSize: const Size(double.infinity, 54),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              elevation: 1,
                            ),
                            onPressed: _isLoading ? null : _handleLogin,
                            child: _isLoading
                                ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                                : Text(
                                    globalLanguage.t('login_btn'),
                                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, letterSpacing: 0.6),
                                  ),
                          ),
                          const SizedBox(height: 16),

                          Center(
                            child: TextButton.icon(
                              onPressed: _showSettingsDialog,
                              icon: const Icon(Icons.settings_outlined, size: 16, color: AppColors.inkSoft),
                              label: Text(globalLanguage.t('server_settings'), style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft)),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Live Camera QR Code Scanner Modal
// ---------------------------------------------------------
class QrCameraScannerModal extends StatefulWidget {
  const QrCameraScannerModal({super.key});

  @override
  State<QrCameraScannerModal> createState() => _QrCameraScannerModalState();
}

class _QrCameraScannerModalState extends State<QrCameraScannerModal> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    facing: CameraFacing.back,
    formats: [
      BarcodeFormat.qrCode,
      BarcodeFormat.code128,
      BarcodeFormat.code39,
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.upcA,
    ],
  );
  bool _hasScanned = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_hasScanned) return;
    final barcode = capture.barcodes.firstOrNull;
    if (barcode == null) return;
    final String? rawValue = barcode.rawValue;
    if (rawValue != null && rawValue.trim().isNotEmpty) {
      _hasScanned = true;
      String cleanCode = rawValue.trim();
      final uri = Uri.tryParse(cleanCode);
      if (uri != null) {
        if (uri.queryParameters.containsKey('item_code')) {
          cleanCode = uri.queryParameters['item_code']!;
        } else if (uri.queryParameters.containsKey('code')) {
          cleanCode = uri.queryParameters['code']!;
        } else if (uri.queryParameters.containsKey('q')) {
          cleanCode = uri.queryParameters['q']!;
        } else if (uri.queryParameters.containsKey('token')) {
          cleanCode = uri.queryParameters['token']!;
        } else if (uri.queryParameters.containsKey('tag')) {
          cleanCode = uri.queryParameters['tag']!;
        }
      }
      Navigator.pop(context, cleanCode);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: AppColors.charcoal2,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Colors.white),
          tooltip: globalLanguage.t('close'),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          globalLanguage.choice('Smart Scanner', 'Smart Scanner'),
          style: const TextStyle(color: AppColors.amberOnDark, fontSize: 16, fontWeight: FontWeight.bold),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.flash_on, color: Colors.white),
            tooltip: globalLanguage.t('cam_torch'),
            onPressed: () => _controller.toggleTorch(),
          ),
          IconButton(
            icon: const Icon(Icons.flip_camera_ios, color: Colors.white),
            tooltip: globalLanguage.t('cam_flip'),
            onPressed: () => _controller.switchCamera(),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: _onDetect,
          ),
          Center(
            child: Container(
              width: 260,
              height: 260,
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.amber, width: 2.5),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Stack(
                children: [
                  Positioned(
                    top: 10,
                    left: 10,
                    child: Container(width: 24, height: 24, decoration: const BoxDecoration(border: Border(top: BorderSide(color: Colors.white, width: 3), left: BorderSide(color: Colors.white, width: 3)))),
                  ),
                  Positioned(
                    top: 10,
                    right: 10,
                    child: Container(width: 24, height: 24, decoration: const BoxDecoration(border: Border(top: BorderSide(color: Colors.white, width: 3), right: BorderSide(color: Colors.white, width: 3)))),
                  ),
                  Positioned(
                    bottom: 10,
                    left: 10,
                    child: Container(width: 24, height: 24, decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Colors.white, width: 3), left: BorderSide(color: Colors.white, width: 3)))),
                  ),
                  Positioned(
                    bottom: 10,
                    right: 10,
                    child: Container(width: 24, height: 24, decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: Colors.white, width: 3), right: BorderSide(color: Colors.white, width: 3)))),
                  ),
                ],
              ),
            ),
          ),
          Positioned(
            bottom: 24,
            left: 20,
            right: 20,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  margin: const EdgeInsets.only(bottom: 14),
                  decoration: BoxDecoration(
                    color: Colors.black87,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: AppColors.amber.withOpacity(0.5)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.qr_code_scanner, color: AppColors.amber, size: 16),
                      const SizedBox(width: 8),
                      Text(
                        globalLanguage.choice(
                          'Itapat sa QR ng Driver o Tag ng Gamit',
                          'Scan Driver QR or Item Tag',
                        ),
                        style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white54),
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 10),
                  ),
                  icon: const Icon(Icons.close, size: 18),
                  label: Text(globalLanguage.t('close')),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------
// Inventory Staff Screen: Verify & Release
// ---------------------------------------------------------
class InventoryVerifyReleaseScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final String? initialToken;
  const InventoryVerifyReleaseScreen({super.key, required this.user, this.initialToken});

  @override
  State<InventoryVerifyReleaseScreen> createState() => _InventoryVerifyReleaseScreenState();
}

class _InventoryVerifyReleaseScreenState extends State<InventoryVerifyReleaseScreen> {
  bool _isLoading = false;
  final TextEditingController _tokenController = TextEditingController();
  Map<String, dynamic>? _searchedRequest;
  bool _isSearching = false;

  // Track verified item line IDs per requisition ID
  final Map<int, Set<int>> _verifiedItemsMap = {};
  // Track live rotating QR handshake per requisition ID
  final Map<int, String> _detectedHandshakeMap = {};
  // Track specifically scanned physical asset units per line ID
  final Map<int, int> _scannedAssetForLine = {};
  final Map<int, String> _scannedTagLabelForLine = {};
  bool _isServerOffline = false;

  @override
  void initState() {
    super.initState();
    if (widget.initialToken != null && widget.initialToken!.trim().isNotEmpty) {
      _tokenController.text = widget.initialToken!.trim();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _lookupToken(widget.initialToken!.trim());
      });
    }
  }

  @override
  void dispose() {
    _tokenController.dispose();
    super.dispose();
  }

  Set<int> _getVerifiedSet(int reqId) {
    return _verifiedItemsMap.putIfAbsent(reqId, () => <int>{});
  }

  void _toggleItemVerification(int reqId, int lineItemId) {
    HapticFeedback.selectionClick();
    setState(() {
      final set = _getVerifiedSet(reqId);
      if (set.contains(lineItemId)) {
        set.remove(lineItemId);
      } else {
        set.add(lineItemId);
      }
    });
  }

  void _verifyAllItems(int reqId, List<dynamic> items) {
    HapticFeedback.mediumImpact();
    setState(() {
      final set = _getVerifiedSet(reqId);
      for (final it in items) {
        final rawId = it['id'];
        final id = rawId is int ? rawId : int.tryParse(rawId.toString()) ?? 0;
        if (id > 0) set.add(id);
      }
    });
  }

  void _unverifyAllItems(int reqId) {
    HapticFeedback.lightImpact();
    setState(() {
      final set = _getVerifiedSet(reqId);
      set.clear();
    });
  }



  Future<void> _openCameraScanner() async {
    final scanned = await Navigator.push<String>(
      context,
      MaterialPageRoute(builder: (_) => const QrCameraScannerModal()),
    );
    if (scanned != null && scanned.trim().isNotEmpty) {
      _tokenController.text = scanned.trim();
      _lookupToken(scanned.trim());
    }
  }

  Future<void> _refreshCurrentScreen() async {
    if (_searchedRequest != null && _tokenController.text.trim().isNotEmpty) {
      await _lookupToken(_tokenController.text);
    }
  }

  Future<void> _lookupToken(String token) async {
    String cleanToken = token.trim();
    if (cleanToken.isEmpty) return;

    // Smart Auto-Routing: If scanned code is an Item or Asset Tag (AST-...)
    if (cleanToken.toUpperCase().startsWith('AST-') ||
        cleanToken.contains('tag=AST-') ||
        cleanToken.contains('item_code=')) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            globalLanguage.choice(
              'Tag ng Gamit ito! Binubuksan ang Kondisyon at Stock...',
              'Item Tag detected! Opening condition & stock...',
            ),
          ),
          backgroundColor: AppColors.blueInfo,
          duration: const Duration(seconds: 2),
        ),
      );
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => ItemStockCheckScreen(
            user: widget.user,
            initialCode: cleanToken,
          ),
        ),
      );
      return;
    }

    if (cleanToken.toUpperCase().startsWith('REQ-') || cleanToken.toUpperCase().startsWith('REQ#') || cleanToken.toUpperCase().startsWith('REQ ')) {
      cleanToken = cleanToken.substring(4).trim();
    } else if (cleanToken.startsWith('#') && !cleanToken.contains('token=')) {
      cleanToken = cleanToken.substring(1).trim();
    }

    String? liveHandshake;
    if (cleanToken.contains('#')) {
      final parts = cleanToken.split('#');
      cleanToken = parts[0];
      if (parts.length > 1) {
        liveHandshake = parts.sublist(1).join('#');
      }
    }

    if (cleanToken.contains('token=')) {
      final match = RegExp(r'[?&]token=([^&]+)').firstMatch(cleanToken);
      if (match != null) {
        cleanToken = Uri.decodeComponent(match.group(1)!);
      }
    }

    setState(() => _isSearching = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php?user_id=${widget.user['id']}&role=${widget.user['role']}&qr_token=${Uri.encodeComponent(cleanToken)}&token=${Uri.encodeComponent(cleanToken)}&auth_token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (payload is List && payload.isNotEmpty) {
          final foundReq = payload.first as Map<String, dynamic>;
          final rawId = foundReq['id'];
          final reqId = rawId is int ? rawId : int.tryParse(rawId.toString()) ?? 0;
          if (liveHandshake != null && reqId > 0) {
            _detectedHandshakeMap[reqId] = liveHandshake;
          }
          setState(() => _searchedRequest = foundReq);
        } else {
          setState(() => _searchedRequest = null);
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('Walang nahanap na requisition para sa token o ID na ito.'),
                backgroundColor: AppColors.redDanger,
              ),
            );
          }
        }
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Hindi makakonekta sa server.'),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    }
    if (mounted) setState(() => _isSearching = false);
  }

  Future<void> _handleReleaseButtonPress(Map<String, dynamic> req) async {
    final rawId = req['id'];
    final int reqId = rawId is int ? rawId : int.parse(rawId.toString());
    final items = (req['items'] as List<dynamic>?) ?? [];
    final verifiedSet = _getVerifiedSet(reqId);
    final totalCount = items.length;
    final verifiedCount = verifiedSet.length;
    final driverName = req['requester_name'] ?? req['driver_name'] ?? 'Driver';

    if (verifiedCount == 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            globalLanguage.isTagalog
                ? 'Paki-verify ang kahit isang gamit sa checklist bago i-release.'
                : 'Please verify at least one item before releasing.',
          ),
          backgroundColor: AppColors.redDanger,
        ),
      );
      return;
    }

    if (verifiedCount >= totalCount) {
      // Check if driver live dynamic QR handshake is present: instant frictionless release!
      if (_detectedHandshakeMap.containsKey(reqId)) {
        final confirmed = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            backgroundColor: AppColors.surface,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            title: Row(
              children: [
                const Icon(Icons.flash_on, color: AppColors.greenOk, size: 24),
                const SizedBox(width: 8),
                Text(
                  globalLanguage.choice('Live Dual-Custody Release', 'Live Dual-Custody Release'),
                  style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
                ),
              ],
            ),
            content: Text(
              globalLanguage.isTagalog
                  ? 'Matagumpay na na-verify ang Live QR ni $driverName!\n\nLahat ng $totalCount gamit ay handa nang i-release para sa Requisition #$reqId nang walang kailangang PIN.'
                  : 'Driver Live QR ($driverName) successfully verified!\n\nAll $totalCount items ready for instant release for Requisition #$reqId without PIN.',
              style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
              ),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.greenOk, foregroundColor: Colors.white),
                onPressed: () => Navigator.pop(ctx, true),
                icon: const Icon(Icons.check_circle_outline, size: 18),
                label: Text(globalLanguage.isTagalog ? 'I-release Agad' : 'Release Now'),
              ),
            ],
          ),
        );

        if (confirmed == true) {
          await _executeRelease(
            reqId,
            req['qr_token']?.toString(),
            verifiedSet.toList(),
            null,
            liveHandshake: _detectedHandshakeMap[reqId],
          );
        }
        return;
      }

      // Fallback: full release confirmation with driver PIN / override
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          title: Row(
            children: [
              const Icon(Icons.check_circle_outline, color: AppColors.greenOk, size: 24),
              const SizedBox(width: 8),
              Text(globalLanguage.t('inv_release_confirm_title'), style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          content: Text(
            globalLanguage.isTagalog
                ? 'Lahat ng $totalCount gamit ay matagumpay na na-verify!\n\nSigurado ka bang nais mong i-release ang mga ito para sa Requisition #$reqId?\n\nMababawas ang stock sa bodega at awtomatikong magtatala ng Tool Loan para sa mga hiram na kagamitan.'
                : 'All $totalCount items verified!\n\nAre you sure you want to release them for Requisition #$reqId?\n\nStock will be deducted from warehouse and tool loans recorded automatically.',
            style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.greenOk, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.isTagalog ? 'I-release Lahat' : 'Release All'),
            ),
          ],
        ),
      );

      if (confirmed == true) {
        await _promptDriverHandshakeAndRelease(req, verifiedSet.toList(), null);
      }
    } else {
      // Partial Short-Picking Release Modal
      final unverifiedItems = items.where((it) {
        final raw = it['id'];
        final lineId = raw is int ? raw : int.tryParse(raw.toString()) ?? 0;
        return !verifiedSet.contains(lineId);
      }).toList();

      await _showPartialReleaseDialog(req, verifiedSet.toList(), unverifiedItems);
    }
  }

  Future<void> _showPartialReleaseDialog(
    Map<String, dynamic> req,
    List<int> verifiedItemIds,
    List<dynamic> unverifiedItems,
  ) async {
    final rawId = req['id'];
    final int reqId = rawId is int ? rawId : int.parse(rawId.toString());
    final reasonController = TextEditingController();
    String? reasonError;

    final commonReasons = [
      globalLanguage.isTagalog ? 'Kulang ang stock sa bodega' : 'Warehouse stock shortage',
      globalLanguage.isTagalog ? 'Hindi kinuha ng driver / nakalimutan' : 'Driver did not pick up / forgotten',
      globalLanguage.isTagalog ? 'Sira o depektibo ang gamit sa rack' : 'Damaged / defective unit on shelf',
      globalLanguage.isTagalog ? 'Nawawala ang gamit sa lokasyon' : 'Item missing from rack location',
    ];

    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return AlertDialog(
              backgroundColor: AppColors.surface,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              titlePadding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
              contentPadding: const EdgeInsets.fromLTRB(20, 14, 20, 0),
              actionsPadding: const EdgeInsets.all(16),
              title: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: AppColors.amberTint,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.warning_amber_rounded, color: AppColors.amber, size: 24),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          globalLanguage.t('inv_partial_warning_title'),
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.ink),
                        ),
                        Text(
                          'Requisition #$reqId (${verifiedItemIds.length} sa ${verifiedItemIds.length + unverifiedItems.length} gamit)',
                          style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              content: SizedBox(
                width: double.maxFinite,
                child: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Missing unverified items container
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.redTint,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: AppColors.redBorder),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.remove_circle_outline, color: AppColors.redDanger, size: 16),
                                const SizedBox(width: 6),
                                Text(
                                  globalLanguage.t('inv_partial_missing_items'),
                                  style: const TextStyle(
                                    color: AppColors.redDanger,
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.bold,
                                    letterSpacing: 0.3,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            ...unverifiedItems.map((it) {
                              final name = it['item_name'] ?? 'Item';
                              final qty = it['quantity_requested'] ?? 1;
                              final unit = it['unit'] ?? 'pc';
                              final variant = it['variant_selected'];
                              return Padding(
                                padding: const EdgeInsets.symmetric(vertical: 2.5),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text('• ', style: TextStyle(color: AppColors.redDanger, fontWeight: FontWeight.bold)),
                                    Expanded(
                                      child: Text(
                                        '$name${variant != null ? " ($variant)" : ""} ($qty $unit)',
                                        style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.redDanger),
                                      ),
                                    ),
                                  ],
                                ),
                              );
                            }),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),

                      // Driver and warehouse liability assurance
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: AppColors.surfaceSubtle,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: AppColors.line),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.shield_outlined, color: AppColors.blueInfo, size: 18),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                globalLanguage.t('inv_partial_driver_protect'),
                                style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft, height: 1.3),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 14),

                      // Quick reason suggestions
                      Text(
                        globalLanguage.isTagalog ? 'Mabilisang Dahilan (Pindutin):' : 'Quick Reason (Tap to select):',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink),
                      ),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: commonReasons.map((r) {
                          final isSelected = reasonController.text == r;
                          return InkWell(
                            onTap: () {
                              setModalState(() {
                                reasonController.text = r;
                                reasonError = null;
                              });
                            },
                            borderRadius: BorderRadius.circular(16),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                              decoration: BoxDecoration(
                                color: isSelected ? AppColors.amberTint : AppColors.surfaceSubtle,
                                borderRadius: BorderRadius.circular(16),
                                border: Border.all(color: isSelected ? AppColors.amber : AppColors.line),
                              ),
                              child: Text(
                                r,
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                                  color: isSelected ? AppColors.amber : AppColors.inkSoft,
                                ),
                              ),
                            ),
                          );
                        }).toList(),
                      ),
                      const SizedBox(height: 10),

                      // Custom reason input
                      TextField(
                        controller: reasonController,
                        maxLines: 2,
                        decoration: InputDecoration(
                          labelText: globalLanguage.t('inv_partial_reason_label'),
                          hintText: globalLanguage.isTagalog ? 'Ilagay ang dahilan kung bakit hindi kumpleto...' : 'Enter reason why incomplete...',
                          errorText: reasonError,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        ),
                        onChanged: (val) {
                          if (reasonError != null && val.trim().isNotEmpty) {
                            setModalState(() => reasonError = null);
                          }
                        },
                      ),
                    ],
                  ),
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.amber,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: () async {
                    final reason = reasonController.text.trim();
                    if (reason.isEmpty) {
                      setModalState(() {
                        reasonError = globalLanguage.isTagalog
                            ? 'Kailangang maglagay ng dahilan bago i-release.'
                            : 'Reason is required for partial release.';
                      });
                      return;
                    }
                    Navigator.pop(ctx);
                    if (_detectedHandshakeMap.containsKey(reqId)) {
                      await _executeRelease(
                        reqId,
                        req['qr_token']?.toString(),
                        verifiedItemIds,
                        reason,
                        liveHandshake: _detectedHandshakeMap[reqId],
                      );
                    } else {
                      await _promptDriverHandshakeAndRelease(req, verifiedItemIds, reason);
                    }
                  },
                  child: Text(globalLanguage.t('inv_partial_btn')),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Future<void> _promptDriverHandshakeAndRelease(
    Map<String, dynamic> req,
    List<int> verifiedItemIds,
    String? partialReason,
  ) async {
    final pinController = TextEditingController();
    final overrideController = TextEditingController();
    bool isSubmitting = false;
    String? errorMsg;

    final driverName = req['requester_name'] ?? req['driver_name'] ?? 'Driver';

    await showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => StatefulBuilder(
        builder: (dialogCtx, setDialogState) {
          return AlertDialog(
            backgroundColor: AppColors.surface,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            title: Row(
              children: [
                const Icon(Icons.shield_outlined, color: AppColors.amber, size: 24),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    globalLanguage.isTagalog ? 'Driver Handshake Verification' : 'Driver Handshake Verification',
                    style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    globalLanguage.choice('4-Digit Driver PIN ($driverName)', '4-Digit Driver PIN ($driverName)'),
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: AppColors.ink),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: pinController,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    obscureText: true,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, letterSpacing: 8),
                    decoration: InputDecoration(
                      hintText: '••••',
                      counterText: '',
                      contentPadding: const EdgeInsets.symmetric(vertical: 12),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    globalLanguage.choice('Supervisor Override Note (opsyonal)', 'Supervisor Override Note (optional)'),
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.inkSoft),
                  ),
                  const SizedBox(height: 4),
                  TextField(
                    controller: overrideController,
                    style: const TextStyle(fontSize: 13),
                    decoration: InputDecoration(
                      hintText: globalLanguage.isTagalog ? 'Hal. Authorized helper ang kumuha' : 'E.g. Authorized helper picked up',
                      contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                  ),
                  if (errorMsg != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      errorMsg!,
                      style: const TextStyle(color: AppColors.redDanger, fontSize: 12, fontWeight: FontWeight.bold),
                    ),
                  ],
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: isSubmitting ? null : () => Navigator.pop(dialogCtx),
                child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.amber, foregroundColor: Colors.white),
                onPressed: isSubmitting
                    ? null
                    : () async {
                        final pin = pinController.text.trim();
                        final override = overrideController.text.trim();
                        if (pin.isEmpty && override.isEmpty) {
                          setDialogState(() {
                            errorMsg = globalLanguage.isTagalog
                                ? 'Kailangan ang 4-digit PIN ng driver o override reason.'
                                : 'Please enter driver PIN or override reason.';
                          });
                          return;
                        }
                        setDialogState(() {
                          isSubmitting = true;
                          errorMsg = null;
                        });

                        final rawReqId = req['id'];
                        final reqId = rawReqId is int ? rawReqId : int.tryParse(rawReqId.toString()) ?? 0;
                        final success = await _executeRelease(
                          reqId,
                          req['qr_token']?.toString(),
                          verifiedItemIds,
                          partialReason,
                          driverPin: pin.isNotEmpty ? pin : null,
                          overrideReason: override.isNotEmpty ? override : null,
                        );

                        if (success) {
                          if (dialogCtx.mounted) {
                            Navigator.pop(dialogCtx);
                          }
                        } else {
                          if (dialogCtx.mounted) {
                            setDialogState(() {
                              isSubmitting = false;
                              errorMsg = globalLanguage.isTagalog
                                  ? 'Maling Driver PIN. Pakisubukan muli o maglagay ng override note.'
                                  : 'Invalid Driver PIN. Please try again or provide override note.';
                            });
                          }
                        }
                      },
                child: isSubmitting
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : Text(globalLanguage.isTagalog ? 'Kumpirmahin' : 'Confirm'),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<bool> _executeRelease(
    int reqId,
    String? qrToken,
    List<int> verifiedItemIds,
    String? partialReason, {
    String? driverPin,
    String? overrideReason,
    String? liveHandshake,
  }) async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      final payload = {
        'action': 'confirm_release',
        'requisition_id': reqId,
        'qr_token': qrToken ?? '',
        'verified_item_ids': verifiedItemIds,
        'partial_release_reason': partialReason,
        'user_id': widget.user['id'],
        'token': widget.user['token'] ?? '',
      };
      if (liveHandshake != null && liveHandshake.isNotEmpty) {
        payload['live_handshake_token'] = liveHandshake;
      }
      if (driverPin != null && driverPin.isNotEmpty) {
        payload['driver_pin'] = driverPin;
      }
      if (overrideReason != null && overrideReason.isNotEmpty) {
        payload['override_reason'] = overrideReason;
      }
      if (_scannedAssetForLine.isNotEmpty) {
        final Map<String, int> assetMap = {};
        _scannedAssetForLine.forEach((k, v) => assetMap[k.toString()] = v);
        payload['asset_choice'] = assetMap;
      }

      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode(payload),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        HapticFeedback.heavyImpact();
        if (!mounted) return true;
        final isPartial = partialReason != null && partialReason.isNotEmpty;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Colors.white, size: 22),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    isPartial
                        ? (globalLanguage.isTagalog
                            ? 'Bahagyang nai-release: ${verifiedItemIds.length} gamit ang naitala. Ang mga kulang ay nanatili sa bodega.'
                            : 'Partially released: ${verifiedItemIds.length} items recorded. Missing items remained in warehouse.')
                        : globalLanguage.t('inv_release_success'),
                  ),
                ),
              ],
            ),
            backgroundColor: isPartial ? AppColors.amber : AppColors.greenOk,
            duration: const Duration(seconds: 4),
          ),
        );
        _verifiedItemsMap.remove(reqId);
        _detectedHandshakeMap.remove(reqId);
        _scannedAssetForLine.clear();
        _scannedTagLabelForLine.clear();
        setState(() => _searchedRequest = null);
        _tokenController.clear();
        return true;
      } else {
        if (!mounted) return false;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(data['error'] ?? data['message'] ?? (globalLanguage.isTagalog ? 'Hindi ma-release ang requisition.' : 'Cannot release requisition.')),
            backgroundColor: AppColors.redDanger,
          ),
        );
        return false;
      }
    } catch (_) {
      if (!mounted) return false;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Server connection error.'), backgroundColor: AppColors.redDanger),
      );
      return false;
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Widget _buildRequestCard(Map<String, dynamic> req, {bool isHighlight = false}) {
    final rawId = req['id'];
    final int reqId = rawId is int ? rawId : int.parse(rawId.toString());
    final requester = req['requester_name'] ?? 'Personnel';
    final truck = req['plate_number'] ?? req['truck_plate_snapshot'] ?? (globalLanguage.isTagalog ? 'Walang Truck' : 'No Vehicle');
    final purpose = req['purpose'] ?? '';
    final items = (req['items'] as List<dynamic>?) ?? [];
    final status = (req['status'] ?? '').toString().toUpperCase();
    final isApproved = status == 'APPROVED';

    final verifiedSet = _getVerifiedSet(reqId);
    final totalCount = items.length;
    final verifiedCount = verifiedSet.length;
    final isAllVerified = totalCount > 0 && verifiedCount == totalCount;
    final isPartialVerified = verifiedCount > 0 && verifiedCount < totalCount;

    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: isHighlight ? AppColors.amber : AppColors.line,
          width: isHighlight ? 2 : 1,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 5,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Top ID & Status Header
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.charcoal2,
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        'REQ #$reqId',
                        style: const TextStyle(
                          color: AppColors.amberOnDark,
                          fontSize: 12.5,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: isApproved ? AppColors.greenTint : AppColors.amberTint,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(
                          color: isApproved ? AppColors.greenBorder : AppColors.amberBorder,
                        ),
                      ),
                      child: Text(
                        isApproved ? (globalLanguage.isTagalog ? 'HANDA NANG I-RELEASE' : 'READY FOR RELEASE') : status,
                        style: TextStyle(
                          color: isApproved ? AppColors.greenOk : AppColors.amber,
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    if (_detectedHandshakeMap.containsKey(reqId)) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                        decoration: BoxDecoration(
                          color: AppColors.greenTint,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: AppColors.greenBorder, width: 1.2),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.flash_on, size: 12, color: AppColors.greenOk),
                            const SizedBox(width: 3),
                            Text(
                              globalLanguage.isTagalog ? 'LIVE HANDSHAKE ✓' : 'LIVE HANDSHAKE ✓',
                              style: const TextStyle(
                                color: AppColors.greenOk,
                                fontSize: 10,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
                Text(
                  req['created_at'] != null ? req['created_at'].toString().split(' ')[0] : '',
                  style: const TextStyle(fontSize: 11.5, color: AppColors.inkLight),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Personnel & Vehicle Info
            Row(
              children: [
                const Icon(Icons.person_outline, size: 18, color: AppColors.inkSoft),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    requester,
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink),
                  ),
                ),
                const SizedBox(width: 10),
                const Icon(Icons.local_shipping_outlined, size: 18, color: AppColors.inkSoft),
                const SizedBox(width: 6),
                Text(
                  truck,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.inkSoft),
                ),
              ],
            ),
            if (purpose.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                '${globalLanguage.isTagalog ? "Layunin" : "Purpose"}: $purpose',
                style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontStyle: FontStyle.italic),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
            const Divider(color: AppColors.line, height: 20),

            // Item Verification Section Header
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  '${globalLanguage.t('inv_items_list')} ($totalCount)',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: AppColors.ink),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2.5),
                  decoration: BoxDecoration(
                    color: isAllVerified
                        ? AppColors.greenTint
                        : (isPartialVerified ? AppColors.amberTint : AppColors.surfaceSubtle),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(
                      color: isAllVerified
                          ? AppColors.greenBorder
                          : (isPartialVerified ? AppColors.amberBorder : AppColors.line),
                    ),
                  ),
                  child: Text(
                    isAllVerified
                        ? (globalLanguage.isTagalog ? 'Lahat Na-verify ($verifiedCount/$totalCount) ✓' : 'All Verified ($verifiedCount/$totalCount) ✓')
                        : (globalLanguage.isTagalog
                            ? '$verifiedCount sa $totalCount gamit na-verify'
                            : '$verifiedCount of $totalCount verified'),
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: isAllVerified
                          ? AppColors.greenOk
                          : (isPartialVerified ? AppColors.amber : AppColors.inkSoft),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),

            // Progress bar
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: totalCount > 0 ? (verifiedCount / totalCount) : 0,
                minHeight: 6,
                backgroundColor: AppColors.line,
                valueColor: AlwaysStoppedAnimation<Color>(
                  isAllVerified ? AppColors.greenOk : AppColors.amber,
                ),
              ),
            ),
            const SizedBox(height: 10),

            // Verification Tools: 1-Tap "I-check Lahat" + Quick Reset (No 2nd camera scan)
            if (isApproved) ...[
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: isAllVerified ? AppColors.greenOk : AppColors.amber,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                        elevation: 1,
                      ),
                      icon: Icon(isAllVerified ? Icons.check_circle : Icons.done_all, size: 19),
                      label: Text(
                        isAllVerified
                            ? (globalLanguage.isTagalog ? 'LAHAT AY NA-CHECK ✓' : 'ALL CHECKED ✓')
                            : (globalLanguage.isTagalog ? 'I-CHECK LAHAT NG GAMIT' : 'CHECK ALL ITEMS'),
                        style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, letterSpacing: 0.3),
                      ),
                      onPressed: () => _verifyAllItems(reqId, items),
                    ),
                  ),
                  if (verifiedCount > 0) ...[
                    const SizedBox(width: 8),
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppColors.inkSoft,
                        side: const BorderSide(color: AppColors.line),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      icon: const Icon(Icons.restart_alt, size: 16),
                      label: Text(
                        globalLanguage.isTagalog ? 'I-reset' : 'Reset',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                      onPressed: () => _unverifyAllItems(reqId),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 10),
            ],

            // Item Checklist Rows
            ...items.map((it) {
              final itName = it['item_name'] ?? 'Item';
              final qty = it['quantity_requested'] ?? 1;
              final unit = it['unit'] ?? 'pc';
              final isBorrow = it['is_borrowable'] == 1 || it['is_borrowable'] == true;
              final variant = it['variant_selected'];
              final rawItemId = it['id'];
              final lineId = rawItemId is int ? rawItemId : int.tryParse(rawItemId.toString()) ?? 0;
              final itemCode = it['item_code'];
              final isVerified = verifiedSet.contains(lineId);

              return Container(
                margin: const EdgeInsets.only(bottom: 6),
                decoration: BoxDecoration(
                  color: isVerified ? AppColors.greenTint.withOpacity(0.4) : AppColors.surfaceSubtle,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: isVerified ? AppColors.greenBorder : AppColors.line,
                    width: isVerified ? 1.5 : 1,
                  ),
                ),
                child: InkWell(
                  borderRadius: BorderRadius.circular(8),
                  onTap: isApproved ? () => _toggleItemVerification(reqId, lineId) : null,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                    child: Row(
                      children: [
                        // Verification Checkbox / Icon
                        Icon(
                          isVerified ? Icons.check_box : Icons.check_box_outline_blank,
                          color: isVerified ? AppColors.greenOk : AppColors.inkSoft,
                          size: 22,
                        ),
                        const SizedBox(width: 10),

                        // Item Details
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '$itName${variant != null ? " ($variant)" : ""}',
                                style: TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                  color: isVerified ? AppColors.ink : AppColors.inkSoft,
                                ),
                              ),
                              Row(
                                children: [
                                  if (itemCode != null && itemCode.toString().isNotEmpty) ...[
                                    Text(
                                      'Code: $itemCode',
                                      style: const TextStyle(fontSize: 10.5, color: AppColors.inkLight),
                                    ),
                                    const SizedBox(width: 8),
                                  ],
                                  if (isBorrow) ...[
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                      decoration: BoxDecoration(
                                        color: AppColors.blueTint,
                                        borderRadius: BorderRadius.circular(4),
                                      ),
                                      child: Text(
                                        globalLanguage.isTagalog ? 'HIRAM' : 'LOAN',
                                        style: const TextStyle(fontSize: 9.5, fontWeight: FontWeight.bold, color: AppColors.blueInfo),
                                      ),
                                    ),
                                    if (_scannedTagLabelForLine.containsKey(lineId)) ...[
                                      const SizedBox(width: 5),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                        decoration: BoxDecoration(
                                          color: AppColors.greenTint,
                                          borderRadius: BorderRadius.circular(4),
                                          border: Border.all(color: AppColors.greenBorder),
                                        ),
                                        child: Text(
                                          '${_scannedTagLabelForLine[lineId]} ✓',
                                          style: const TextStyle(fontSize: 9.5, fontWeight: FontWeight.bold, color: AppColors.greenOk),
                                        ),
                                      ),
                                    ],
                                  ] else ...[
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                      decoration: BoxDecoration(
                                        color: AppColors.greenTint,
                                        borderRadius: BorderRadius.circular(4),
                                      ),
                                      child: Text(
                                        globalLanguage.isTagalog ? 'KONSUMO' : 'CONSUMABLE',
                                        style: const TextStyle(fontSize: 9.5, fontWeight: FontWeight.bold, color: AppColors.greenOk),
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                            ],
                          ),
                        ),

                        // Qty Badge & Verification Chip
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(
                                color: isBorrow ? AppColors.blueTint : AppColors.paper,
                                borderRadius: BorderRadius.circular(5),
                                border: Border.all(color: isBorrow ? AppColors.blueBorder : AppColors.line),
                              ),
                              child: Text(
                                '$qty $unit',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                  color: isBorrow ? AppColors.blueInfo : AppColors.ink,
                                ),
                              ),
                            ),
                            const SizedBox(height: 3),
                            Text(
                              isVerified
                                  ? globalLanguage.t('inv_verified_status')
                                  : globalLanguage.t('inv_unverified_status'),
                              style: TextStyle(
                                fontSize: 9.5,
                                fontWeight: FontWeight.w600,
                                color: isVerified ? AppColors.greenOk : AppColors.amber,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }),

            const SizedBox(height: 12),

            // Smart Dynamic Release Button
            if (isApproved) ...[
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: verifiedCount == 0
                        ? AppColors.inkSoft.withOpacity(0.3)
                        : (isAllVerified ? AppColors.greenOk : AppColors.amber),
                    foregroundColor: Colors.white,
                    minimumSize: const Size(double.infinity, 52),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    elevation: verifiedCount == 0 ? 0 : 2,
                  ),
                  icon: Icon(
                    verifiedCount == 0
                        ? Icons.checklist
                        : (isAllVerified ? Icons.check_circle_outline : Icons.warning_amber_rounded),
                    size: 22,
                  ),
                  label: Text(
                    verifiedCount == 0
                        ? (globalLanguage.isTagalog
                            ? 'I-CHECK MUNA ANG MGA GAMIT BAGO I-RELEASE'
                            : 'CHECK ITEMS BEFORE RELEASE')
                        : (isAllVerified
                            ? (globalLanguage.isTagalog
                                ? 'I-RELEASE ANG LAHAT NG GAMIT ($totalCount/$totalCount)'
                                : 'RELEASE ALL ITEMS ($totalCount/$totalCount)')
                            : (globalLanguage.isTagalog
                                ? 'BAHAGYANG RELEASE LAMANG ($verifiedCount/$totalCount NA-VERIFY)'
                                : 'PARTIAL RELEASE ONLY ($verifiedCount/$totalCount VERIFIED)')),
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, letterSpacing: 0.3),
                  ),
                  onPressed: _isLoading ? null : () => _handleReleaseButtonPress(req),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.isTagalog ? 'Release ng Gamit' : 'Verify & Release',
        user: widget.user,
      ),
      body: RefreshIndicator(
        onRefresh: _refreshCurrentScreen,
        color: AppColors.amber,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
          children: [
            // Ambient Offline / Connectivity Status Banner
            if (_isServerOffline) ...[
              Container(
                margin: const EdgeInsets.only(bottom: 14),
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: AppColors.amberTint,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppColors.amberBorder),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.cloud_off_rounded, color: AppColors.amber, size: 22),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        globalLanguage.isTagalog
                            ? 'Walang koneksyon sa bodega server. I-tap para i-retry.'
                            : 'Warehouse server unreachable. Tap to retry.',
                        style: const TextStyle(fontSize: 12, color: AppColors.amberDim, fontWeight: FontWeight.w600),
                      ),
                    ),
                    TextButton(
                      style: TextButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        backgroundColor: AppColors.amber,
                        foregroundColor: Colors.white,
                      ),
                      onPressed: _refreshCurrentScreen,
                      child: Text(
                        globalLanguage.isTagalog ? 'I-refresh' : 'Retry',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11.5),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            // Big Hero QR Scanner Card (High Visibility & User Friendly)
            Container(
              margin: const EdgeInsets.only(bottom: 14),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.charcoal, AppColors.charcoal2],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(14),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.12),
                    blurRadius: 8,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: AppColors.amber.withOpacity(0.25),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(Icons.qr_code_scanner, color: AppColors.amberOnDark, size: 28),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                globalLanguage.t('inv_hero_title'),
                                style: const TextStyle(
                                  color: AppColors.amberOnDark,
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  letterSpacing: 0.5,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.amber,
                        foregroundColor: Colors.white,
                        minimumSize: const Size(double.infinity, 54),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        elevation: 2,
                      ),
                      icon: const Icon(Icons.camera_alt, size: 22),
                      label: Text(
                        globalLanguage.t('inv_scan_btn'),
                        style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, letterSpacing: 0.5),
                      ),
                      onPressed: _openCameraScanner,
                    ),
                  ],
                ),
              ),
            ),

            // Manual Code Lookup Fallback
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      globalLanguage.t('inv_manual_label'),
                      style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.ink),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _tokenController,
                            style: const TextStyle(fontSize: 14),
                            decoration: InputDecoration(
                              hintText: globalLanguage.t('inv_manual_hint'),
                              prefixIcon: const Icon(Icons.search, size: 18, color: AppColors.inkSoft),
                              suffixIcon: _tokenController.text.isNotEmpty
                                  ? IconButton(
                                      icon: const Icon(Icons.clear, size: 18),
                                      onPressed: () {
                                        _tokenController.clear();
                                        setState(() => _searchedRequest = null);
                                      },
                                    )
                                  : null,
                              contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
                            ),
                            onSubmitted: _lookupToken,
                          ),
                        ),
                        const SizedBox(width: 8),
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.charcoal2,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                          ),
                          onPressed: _isSearching ? null : () => _lookupToken(_tokenController.text),
                          child: _isSearching
                              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                              : Text(globalLanguage.t('inv_lookup_btn')),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 14),
            if (_searchedRequest != null) ...[
              Card(
                color: AppColors.surface,
                elevation: 1,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: const BorderSide(color: AppColors.amber, width: 1.5),
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  child: Row(
                    children: [
                      const Icon(Icons.qr_code_scanner, color: AppColors.amber, size: 20),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          globalLanguage.isTagalog ? 'Aktibong Na-scan na Requisition' : 'Active Scanned Requisition',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink),
                        ),
                      ),
                      TextButton.icon(
                        style: TextButton.styleFrom(
                          foregroundColor: AppColors.redDanger,
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          visualDensity: VisualDensity.compact,
                        ),
                        onPressed: () {
                          setState(() {
                            final rawId = _searchedRequest!['id'];
                            final int reqId = rawId is int ? rawId : int.tryParse(rawId.toString()) ?? 0;
                            _verifiedItemsMap.remove(reqId);
                            _detectedHandshakeMap.remove(reqId);
                            _scannedAssetForLine.clear();
                            _scannedTagLabelForLine.clear();
                            _searchedRequest = null;
                            _tokenController.clear();
                          });
                        },
                        icon: const Icon(Icons.close, size: 16),
                        label: Text(
                          globalLanguage.isTagalog ? 'I-clear / Bagong Scan' : 'Clear / New Scan',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 10),
              _buildRequestCard(_searchedRequest!, isHighlight: true),
            ] else ...[
              // Pure Awaiting Scan State: zero requisitions or item cards displayed until scanned
              Card(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 40, horizontal: 24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        padding: const EdgeInsets.all(18),
                        decoration: BoxDecoration(
                          color: AppColors.amber.withOpacity(0.12),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.qr_code_scanner_rounded, size: 48, color: AppColors.amber),
                      ),
                      const SizedBox(height: 18),
                      Text(
                        globalLanguage.isTagalog ? 'Naka-antabay sa Pag-scan' : 'Waiting for QR Code Scan',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 16.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        globalLanguage.isTagalog
                            ? 'I-scan ang Request QR Code ng Driver mula sa kanilang Request Slip o i-type ang code sa itaas upang buksan ang item checklist at release button.'
                            : 'Scan the Driver Request QR Code from their slip or enter the code above to open item checklist and release button.',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 13, color: AppColors.inkSoft, height: 1.45),
                      ),
                      const SizedBox(height: 18),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                        decoration: BoxDecoration(
                          color: AppColors.surfaceSubtle,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: AppColors.line),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.lock_outline, size: 16, color: AppColors.inkSoft),
                            const SizedBox(width: 8),
                            Text(
                              globalLanguage.isTagalog
                                  ? 'Naka-lock ang checklist hangga\'t walang scan'
                                  : 'Checklist locked until QR code is scanned',
                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.inkSoft),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Inventory Staff Screen: Tool Loans & Returns
// ---------------------------------------------------------
class InventoryLoansScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const InventoryLoansScreen({super.key, required this.user});

  @override
  State<InventoryLoansScreen> createState() => _InventoryLoansScreenState();
}

class _InventoryLoansScreenState extends State<InventoryLoansScreen> {
  bool _isLoading = false;
  List<dynamic> _loans = [];
  String _selectedFilter = 'all';

  @override
  void initState() {
    super.initState();
    _fetchLoans();
  }

  Future<void> _fetchLoans() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/loans.php?user_id=${widget.user['id']}&role=${widget.user['role']}&tab=$_selectedFilter&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (mounted) {
          setState(() {
            _loans = payload is List ? payload : [];
          });
        }
      }
    } catch (_) {}
    if (mounted) setState(() => _isLoading = false);
  }

  Future<void> _decideExtension(int loanId, bool approve) async {
    final actionName = approve ? globalLanguage.t('approve') : globalLanguage.t('reject');
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(
          globalLanguage.isTagalog ? '$actionName ang Extension Request?' : '$actionName Extension Request?',
          style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
        ),
        content: Text(
          globalLanguage.isTagalog
              ? 'Sigurado ka bang nais mong $actionName ang pagpapalawig ng hiram na gamit na ito?'
              : 'Are you sure you want to $actionName the loan extension for this tool?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: approve ? AppColors.greenOk : AppColors.redDanger,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(actionName),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/loans.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'decide_extension',
          'loan_id': loanId,
          'approve': approve,
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              globalLanguage.isTagalog
                  ? 'Extension request ${approve ? "inaprubahan" : "tinanggihan"}.'
                  : 'Extension request ${approve ? "approved" : "declined"}.',
            ),
            backgroundColor: approve ? AppColors.greenOk : AppColors.redDanger,
          ),
        );
        _fetchLoans();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(data['error'] ?? data['message'] ?? 'Nagka-error sa extension decision.'),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Server connection error.'), backgroundColor: AppColors.redDanger),
      );
    }
  }

  Future<void> _returnTool(int loanId, String itemName, int qty) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(
          globalLanguage.isTagalog ? 'Tanggapin ang Pagbabalik ng Gamit' : 'Accept Tool Return',
          style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
        ),
        content: Text(
          globalLanguage.isTagalog
              ? 'Kumpirmahin na naibalik na ni driver ang "$itemName" (Dami: $qty) sa bodega?'
              : 'Confirm that "$itemName" (Qty: $qty) has been returned to warehouse stock?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.amber, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(globalLanguage.isTagalog ? 'I-check In sa Stock' : 'Check In to Stock'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/loans.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'return_tool',
          'loan_id': loanId,
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              globalLanguage.isTagalog
                  ? 'Naibalik na sa bodega ang $itemName.'
                  : '$itemName successfully returned to warehouse stock.',
            ),
            backgroundColor: AppColors.greenOk,
          ),
        );
        _fetchLoans();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(data['error'] ?? data['message'] ?? 'Hindi ma-check in ang gamit.'),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Server connection error.'), backgroundColor: AppColors.redDanger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.t('loan_title'),
        user: widget.user,
      ),
      body: Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            color: AppColors.surface,
            child: Row(
              children: [
                _buildFilterChip(globalLanguage.isTagalog ? 'Lahat' : 'All', 'all'),
                const SizedBox(width: 8),
                _buildFilterChip(globalLanguage.isTagalog ? 'May Extension' : 'Extensions', 'extensions'),
                const SizedBox(width: 8),
                _buildFilterChip(globalLanguage.isTagalog ? 'Lampas sa Araw' : 'Overdue', 'overdue'),
              ],
            ),
          ),
          const Divider(height: 1, color: AppColors.line),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _fetchLoans,
              color: AppColors.amber,
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
                  : _loans.isEmpty
                      ? Center(
                          child: Padding(
                            padding: const EdgeInsets.all(32),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.handyman_outlined, size: 54, color: AppColors.inkLight),
                                const SizedBox(height: 12),
                                Text(
                                  globalLanguage.isTagalog ? 'Walang Hiniram na Gamit' : 'No Borrowed Tools',
                                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.ink),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  _selectedFilter == 'extensions'
                                      ? (globalLanguage.isTagalog ? 'Walang nakabinbing kahilingan sa pagpapalawig ng hiram.' : 'No pending loan extension requests.')
                                      : _selectedFilter == 'overdue'
                                          ? (globalLanguage.isTagalog ? 'Walang overdue na gamit. Lahat ay naibalik o nasa takdang oras.' : 'No overdue tool loans found.')
                                          : (globalLanguage.isTagalog ? 'Lahat ng kagamitan sa bodega ay kumpleto at nasa bodega.' : 'All equipment in warehouse is accounted for.'),
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                                ),
                              ],
                            ),
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
                          itemCount: _loans.length,
                          itemBuilder: (ctx, idx) {
                            final loan = _loans[idx] as Map<String, dynamic>;
                            final loanId = loan['id'];
                            final itemName = loan['item_name'] ?? 'Tool';
                            final borrower = loan['borrower_name'] ?? 'Driver';
                            final truck = loan['plate_number'] ?? loan['truck_plate_snapshot'] ?? 'N/A';
                            final dueDate = loan['due_date'] ?? '';
                            final daysLeft = loan['days_left'];
                            final status = loan['status'] ?? 'borrowed';
                            final extStatus = loan['extension_status'] ?? 'none';
                            final extDays = loan['extension_days'] ?? 0;
                            final extReason = loan['extension_reason'] ?? '';
                            final isOverdue = status == 'overdue';
                            final isExtPending = extStatus == 'pending';

                            return Container(
                              margin: const EdgeInsets.only(bottom: 12),
                              decoration: BoxDecoration(
                                color: AppColors.surface,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                  color: isExtPending
                                      ? AppColors.amber
                                      : isOverdue
                                          ? AppColors.redBorder
                                          : AppColors.line,
                                  width: isExtPending ? 1.6 : 1,
                                ),
                              ),
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        AppItemImage(imageUrl: loan['image_url'], width: 42, height: 42),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                itemName,
                                                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink),
                                              ),
                                              const SizedBox(height: 2),
                                              Text(
                                                'Hiniram ni: $borrower • Truck: $truck',
                                                style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                                              ),
                                            ],
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                                          decoration: BoxDecoration(
                                            color: isOverdue
                                                ? AppColors.redTint
                                                : isExtPending
                                                    ? AppColors.amberTint
                                                    : AppColors.greenTint,
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(
                                              color: isOverdue
                                                  ? AppColors.redBorder
                                                  : isExtPending
                                                      ? AppColors.amberBorder
                                                      : AppColors.greenBorder,
                                            ),
                                          ),
                                          child: Text(
                                            isOverdue
                                                ? 'OVERDUE'
                                                : isExtPending
                                                    ? 'EXTENSION REQ'
                                                    : '$daysLeft araw natitira',
                                            style: TextStyle(
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                              color: isOverdue
                                                  ? AppColors.redDanger
                                                  : isExtPending
                                                      ? AppColors.amber
                                                      : AppColors.greenOk,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Text(
                                          'Dami: ${loan['quantity']} ${loan['unit'] ?? "pc"}',
                                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink),
                                        ),
                                        Text(
                                          'Due: $dueDate',
                                          style: TextStyle(
                                            fontSize: 12,
                                            color: isOverdue ? AppColors.redDanger : AppColors.inkSoft,
                                            fontWeight: isOverdue ? FontWeight.bold : FontWeight.normal,
                                          ),
                                        ),
                                      ],
                                    ),
                                    if (isExtPending) ...[
                                      const SizedBox(height: 10),
                                      Container(
                                        padding: const EdgeInsets.all(10),
                                        decoration: BoxDecoration(
                                          color: AppColors.amberTint,
                                          borderRadius: BorderRadius.circular(6),
                                          border: Border.all(color: AppColors.amberBorder),
                                        ),
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              'Hinihiling na Extension: +$extDays araw',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12.5, color: AppColors.amberDim),
                                            ),
                                            if (extReason.isNotEmpty) ...[
                                              const SizedBox(height: 2),
                                              Text(
                                                'Dahilan: $extReason',
                                                style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                                              ),
                                            ],
                                            const SizedBox(height: 8),
                                            Row(
                                              children: [
                                                Expanded(
                                                  child: ElevatedButton.icon(
                                                    style: ElevatedButton.styleFrom(
                                                      backgroundColor: AppColors.greenOk,
                                                      foregroundColor: Colors.white,
                                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                                    ),
                                                    icon: const Icon(Icons.check, size: 16),
                                                    label: Text(globalLanguage.t('approve'), style: const TextStyle(fontSize: 12)),
                                                    onPressed: () => _decideExtension(loanId, true),
                                                  ),
                                                ),
                                                const SizedBox(width: 8),
                                                Expanded(
                                                  child: OutlinedButton.icon(
                                                    style: OutlinedButton.styleFrom(
                                                      foregroundColor: AppColors.redDanger,
                                                      side: const BorderSide(color: AppColors.redBorder),
                                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                                    ),
                                                    icon: const Icon(Icons.close, size: 16),
                                                    label: Text(globalLanguage.t('reject'), style: const TextStyle(fontSize: 12)),
                                                    onPressed: () => _decideExtension(loanId, false),
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                    const Divider(color: AppColors.line, height: 16),
                                    SizedBox(
                                      width: double.infinity,
                                      child: OutlinedButton.icon(
                                        style: OutlinedButton.styleFrom(
                                          foregroundColor: AppColors.amber,
                                          side: const BorderSide(color: AppColors.amberBorder),
                                          padding: const EdgeInsets.symmetric(vertical: 9),
                                        ),
                                        icon: const Icon(Icons.keyboard_return, size: 16),
                                        label: Text(globalLanguage.isTagalog ? 'Tanggapin ang Pagbabalik sa Bodega' : 'Check In Return to Warehouse', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold)),
                                        onPressed: () => _returnTool(loanId, itemName, loan['quantity'] ?? 1),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(String label, String value) {
    final isSelected = _selectedFilter == value;
    return InkWell(
      onTap: () {
        setState(() => _selectedFilter = value);
        _fetchLoans();
      },
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.amber : AppColors.surfaceSubtle,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: isSelected ? AppColors.amber : AppColors.line,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 12,
            fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
            color: isSelected ? Colors.white : AppColors.inkSoft,
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Inventory Staff Screen: Stock & Warehouse Catalog
// ---------------------------------------------------------
// ---------------------------------------------------------
// Item Stock Check Screen: Scan QR → View Real-Time Stock
// ---------------------------------------------------------
class ItemStockCheckScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final String? initialCode;
  const ItemStockCheckScreen({super.key, required this.user, this.initialCode});

  @override
  State<ItemStockCheckScreen> createState() => _ItemStockCheckScreenState();
}

class _ItemStockCheckScreenState extends State<ItemStockCheckScreen> {
  final TextEditingController _codeCtrl = TextEditingController();
  bool _isLoading = false;
  Map<String, dynamic>? _result;
  Map<String, dynamic>? _assetResult;
  String? _errorMsg;
  String _baseUrl = '';

  @override
  void initState() {
    super.initState();
    if (widget.initialCode != null && widget.initialCode!.trim().isNotEmpty) {
      _codeCtrl.text = widget.initialCode!.trim();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _lookupItem(widget.initialCode!.trim());
      });
    }
  }

  Future<void> _openScanner() async {
    final code = await Navigator.push<String>(
      context,
      MaterialPageRoute(builder: (_) => const QrCameraScannerModal()),
    );
    if (code != null && code.trim().isNotEmpty && mounted) {
      _codeCtrl.text = code.trim();
      _lookupItem(code.trim());
    }
  }

  Future<void> _lookupItem(String code) async {
    final clean = code.trim();
    if (clean.isEmpty) return;

    // Smart Auto-Routing: Detect if scanned code is a Driver Requisition QR / Token
    String tokenCandidate = clean;
    final uri = Uri.tryParse(clean);
    if (uri != null && uri.queryParameters.containsKey('token')) {
      tokenCandidate = uri.queryParameters['token']!;
    }
    final isReqToken = (RegExp(r'^[0-9a-fA-F]{32}').hasMatch(tokenCandidate)) ||
        tokenCandidate.toUpperCase().startsWith('REQ-') ||
        tokenCandidate.toUpperCase().startsWith('REQ#') ||
        tokenCandidate.toLowerCase().contains('token=');

    if (isReqToken) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            globalLanguage.choice(
              'QR ng Driver ito! Binubuksan ang Release Verification...',
              'Driver Request QR detected! Opening Release Verification...',
            ),
          ),
          backgroundColor: AppColors.greenOk,
          duration: const Duration(seconds: 2),
        ),
      );
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => InventoryVerifyReleaseScreen(
            user: widget.user,
            initialToken: tokenCandidate,
          ),
        ),
      );
      return;
    }

    setState(() { _isLoading = true; _errorMsg = null; _result = null; _assetResult = null; });

    // Detect if code is an asset tag (AST-...) or contains tag=AST-...
    String tagCandidate = clean;
    if (uri != null && uri.queryParameters.containsKey('tag')) {
      tagCandidate = uri.queryParameters['tag']!;
    }
    final isAsset = tagCandidate.toUpperCase().startsWith('AST-');

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      if (mounted) setState(() => _baseUrl = baseUrl);

      if (isAsset) {
        final url = Uri.parse('$baseUrl/asset_check.php?tag=${Uri.encodeComponent(tagCandidate)}');
        final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false))
            .timeout(const Duration(seconds: 10));
        if (res.statusCode == 200) {
          final data = jsonDecode(res.body);
          if (data['success'] == true && data['data'] != null) {
            if (mounted) setState(() => _assetResult = data['data']);
          } else {
            if (mounted) setState(() => _errorMsg = data['error'] ?? globalLanguage.t('isc_not_found'));
          }
        } else if (res.statusCode == 404) {
          if (mounted) setState(() => _errorMsg = globalLanguage.isTagalog ? 'Hindi nahanap ang asset.' : 'Asset not found.');
        } else {
          if (mounted) setState(() => _errorMsg = 'Server error (${res.statusCode})');
        }
      } else {
        final url = Uri.parse('$baseUrl/item_lookup.php?item_code=${Uri.encodeComponent(clean)}');
        final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false))
            .timeout(const Duration(seconds: 10));
        if (res.statusCode == 200) {
          final data = jsonDecode(res.body);
          if (data['success'] == true && data['data'] != null) {
            if (mounted) setState(() => _result = data['data']);
          } else {
            if (mounted) setState(() => _errorMsg = data['error'] ?? globalLanguage.t('isc_not_found'));
          }
        } else if (res.statusCode == 404) {
          if (mounted) setState(() => _errorMsg = globalLanguage.t('isc_not_found'));
        } else {
          if (mounted) setState(() => _errorMsg = 'Server error (${res.statusCode})');
        }
      }
    } catch (e) {
      if (mounted) setState(() => _errorMsg = 'Network error: $e');
    }
    if (mounted) setState(() => _isLoading = false);
  }

  void _reset() {
    setState(() { _result = null; _assetResult = null; _errorMsg = null; _codeCtrl.clear(); });
  }

  @override
  void dispose() {
    _codeCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: _assetResult != null
            ? globalLanguage.t('ahc_title')
            : globalLanguage.t('nav_scan_item'),
        user: widget.user,
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
          : _assetResult != null
              ? _buildAssetHealthView(_assetResult!)
              : _result != null
                  ? _buildResultView()
                  : _buildScanView(),
    );
  }

  Widget _buildScanView() {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 84),
      child: Column(
        children: [
          const SizedBox(height: 30),
          // Hero Icon
          Container(
            width: 100, height: 100,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [AppColors.amber.withOpacity(0.15), AppColors.amberTint],
                begin: Alignment.topLeft, end: Alignment.bottomRight,
              ),
              shape: BoxShape.circle,
              border: Border.all(color: AppColors.amberBorder, width: 2),
            ),
            child: const Icon(Icons.qr_code_2, size: 50, color: AppColors.amber),
          ),
          const SizedBox(height: 20),
          Text(
            globalLanguage.t('isc_hero_title'),
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.ink, letterSpacing: 0.5),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 24),
          // Scan Button
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.amber,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                elevation: 3,
              ),
              icon: const Icon(Icons.qr_code_scanner, size: 22),
              label: Text(globalLanguage.t('isc_scan_btn'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, letterSpacing: 0.5)),
              onPressed: _openScanner,
            ),
          ),
          const SizedBox(height: 24),
          // Divider
          Row(
            children: [
              const Expanded(child: Divider(color: AppColors.line)),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Text(
                  globalLanguage.isTagalog ? 'O' : 'OR',
                  style: const TextStyle(fontSize: 12, color: AppColors.inkLight, fontWeight: FontWeight.bold),
                ),
              ),
              const Expanded(child: Divider(color: AppColors.line)),
            ],
          ),
          const SizedBox(height: 16),
          // Manual Input
          Text(
            globalLanguage.t('isc_manual_label'),
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.inkSoft),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _codeCtrl,
                  style: const TextStyle(fontSize: 14),
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(
                    hintText: globalLanguage.t('isc_manual_hint'),
                    prefixIcon: const Icon(Icons.search, size: 20, color: AppColors.inkSoft),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                    focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.amber, width: 2)),
                  ),
                  onSubmitted: (val) => _lookupItem(val.trim()),
                ),
              ),
              const SizedBox(width: 8),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.charcoal2,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () => _lookupItem(_codeCtrl.text.trim()),
                child: Text(globalLanguage.t('isc_lookup_btn'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              ),
            ],
          ),
          // Error
          if (_errorMsg != null) ...[
            const SizedBox(height: 20),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.redTint,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.redBorder),
              ),
              child: Row(
                children: [
                  const Icon(Icons.error_outline, color: AppColors.redDanger, size: 20),
                  const SizedBox(width: 10),
                  Expanded(child: Text(_errorMsg!, style: const TextStyle(color: AppColors.redDanger, fontSize: 13, fontWeight: FontWeight.w600))),
                ],
              ),
            ),
          ],
          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _buildResultView() {
    final item = (_result!['item'] ?? {}) as Map<String, dynamic>;
    final loans = (_result!['active_loans'] ?? {}) as Map<String, dynamic>;
    final variants = (_result!['variants'] ?? []) as List<dynamic>;
    final activity = (_result!['activity'] ?? []) as List<dynamic>;

    final stock = (item['quantity_on_hand'] ?? 0) as int;
    final status = (item['stock_status'] ?? 'in_stock').toString();
    final unit = (item['unit'] ?? 'pc').toString();
    final imgUrl = AppConfig.resolveImageUrl(item['image_url']?.toString(), activeBaseUrl: _baseUrl);

    Color statusColor;
    String statusLabel;
    Color statusBg;
    Color statusBorder;
    if (status == 'out_of_stock') {
      statusColor = AppColors.redDanger; statusLabel = globalLanguage.t('stock_out');
      statusBg = AppColors.redTint; statusBorder = AppColors.redBorder;
    } else if (status == 'low_stock') {
      statusColor = AppColors.amber; statusLabel = globalLanguage.t('stock_low');
      statusBg = AppColors.amberTint; statusBorder = AppColors.amberBorder;
    } else {
      statusColor = AppColors.greenOk; statusLabel = globalLanguage.t('stock_available');
      statusBg = AppColors.greenTint; statusBorder = AppColors.greenBorder;
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 84),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // ─── Item Header Card ───
          Container(
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.line),
              boxShadow: [
                BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 8, offset: const Offset(0, 2)),
              ],
            ),
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    AppItemImage(imageUrl: imgUrl, width: 72, height: 72, borderRadius: BorderRadius.circular(10)),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            item['name']?.toString() ?? 'Item',
                            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
                          ),
                          if ((item['brand'] ?? '').toString().isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(item['brand'].toString(), style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontWeight: FontWeight.w500)),
                          ],
                          const SizedBox(height: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: AppColors.charcoal2,
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: Text(
                              item['item_code']?.toString() ?? '',
                              style: const TextStyle(fontSize: 11, color: AppColors.amberOnDark, fontWeight: FontWeight.bold, letterSpacing: 0.5),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${item['category_name'] ?? 'General'}',
                            style: const TextStyle(fontSize: 11, color: AppColors.inkLight),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                if ((item['description'] ?? '').toString().isNotEmpty) ...[
                  const SizedBox(height: 10),
                  const Divider(height: 1, color: AppColors.line),
                  const SizedBox(height: 10),
                  Text(
                    item['description'].toString(),
                    style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, height: 1.4),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 14),

          // ─── Stock Gauge ───
          Container(
            decoration: BoxDecoration(
              color: statusBg,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: statusBorder),
            ),
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        globalLanguage.t('isc_stock_label'),
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: statusColor),
                      ),
                      const SizedBox(height: 4),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.baseline,
                        textBaseline: TextBaseline.alphabetic,
                        children: [
                          Text(
                            '$stock',
                            style: TextStyle(fontSize: 36, fontWeight: FontWeight.bold, color: statusColor),
                          ),
                          const SizedBox(width: 6),
                          Text(unit, style: TextStyle(fontSize: 14, color: statusColor, fontWeight: FontWeight.w500)),
                        ],
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: statusColor.withOpacity(0.3)),
                  ),
                  child: Text(
                    statusLabel,
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: statusColor),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // ─── Location + Loans Row ───
          Row(
            children: [
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.place, size: 16, color: AppColors.amber),
                          const SizedBox(width: 6),
                          Text(globalLanguage.t('isc_location_label'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.inkSoft)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(item['location']?.toString() ?? 'Bodega', style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.ink)),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.handyman, size: 16, color: AppColors.blueInfo),
                          const SizedBox(width: 6),
                          Flexible(child: Text(globalLanguage.t('isc_loans_label'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.inkSoft), overflow: TextOverflow.ellipsis)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        '${loans['loaned_qty'] ?? 0} $unit',
                        style: TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w600,
                          color: (loans['loaned_qty'] ?? 0) > 0 ? AppColors.blueInfo : AppColors.ink,
                        ),
                      ),
                      if (loans['earliest_due'] != null) ...[
                        const SizedBox(height: 3),
                        Text(
                          '${globalLanguage.t('isc_due_label')}: ${loans['earliest_due']}',
                          style: const TextStyle(fontSize: 10, color: AppColors.inkLight),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),

          // ─── Variants ───
          if (variants.isNotEmpty) ...[
            const SizedBox(height: 14),
            Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppColors.line),
              ),
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.style, size: 16, color: AppColors.amber),
                      const SizedBox(width: 6),
                      Text(
                        '${globalLanguage.t('isc_variants_label')} (${item['variant_label'] ?? ''})',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  ...variants.map((v) {
                    final vMap = v as Map<String, dynamic>;
                    final vQty = (vMap['quantity_on_hand'] ?? 0) as int;
                    final vOut = vQty <= 0;
                    final vLow = vQty > 0 && vQty <= 5;
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              vMap['variant_value']?.toString() ?? '',
                              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: AppColors.ink),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: vOut ? AppColors.redTint : (vLow ? AppColors.amberTint : AppColors.greenTint),
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: vOut ? AppColors.redBorder : (vLow ? AppColors.amberBorder : AppColors.greenBorder)),
                            ),
                            child: Text(
                              '$vQty $unit',
                              style: TextStyle(
                                fontSize: 12, fontWeight: FontWeight.bold,
                                color: vOut ? AppColors.redDanger : (vLow ? AppColors.amber : AppColors.greenOk),
                              ),
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
                ],
              ),
            ),
          ],

          // ─── Recent Activity ───
          const SizedBox(height: 14),
          Container(
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.line),
            ),
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.history, size: 16, color: AppColors.amber),
                    const SizedBox(width: 6),
                    Text(globalLanguage.t('isc_activity_label'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.inkSoft)),
                  ],
                ),
                const SizedBox(height: 10),
                if (activity.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(globalLanguage.t('isc_no_activity'), style: const TextStyle(fontSize: 12, color: AppColors.inkLight)),
                  )
                else
                  ...activity.take(10).map((a) {
                    final aMap = a as Map<String, dynamic>;
                    final aStatus = (aMap['status'] ?? '').toString();
                    Color sBadgeColor;
                    switch (aStatus) {
                      case 'released': sBadgeColor = AppColors.greenOk; break;
                      case 'approved': sBadgeColor = AppColors.blueInfo; break;
                      case 'declined': sBadgeColor = AppColors.redDanger; break;
                      default: sBadgeColor = AppColors.inkLight;
                    }
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            width: 8, height: 8,
                            margin: const EdgeInsets.only(top: 5, right: 10),
                            decoration: BoxDecoration(shape: BoxShape.circle, color: sBadgeColor),
                          ),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${aMap['requester'] ?? 'Unknown'} — ${aMap['qty'] ?? 0} $unit',
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink),
                                ),
                                Text(
                                  '${aMap['requested_at'] ?? ''} • ${aStatus.toUpperCase()}${aMap['variant'] != null ? ' • ${aMap['variant']}' : ''}',
                                  style: const TextStyle(fontSize: 10, color: AppColors.inkLight),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── Scan Another Button ───
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.amber,
                side: const BorderSide(color: AppColors.amber, width: 1.5),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              icon: const Icon(Icons.qr_code_scanner, size: 20),
              label: Text(globalLanguage.t('isc_scan_another'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              onPressed: _reset,
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  // ─────────────────────────────────────────────────────────────
  // Asset Health & Condition Inspection View
  // ─────────────────────────────────────────────────────────────
  Widget _buildAssetHealthView(Map<String, dynamic> data) {
    final asset = (data['asset'] ?? {}) as Map<String, dynamic>;
    final item = (data['item'] ?? {}) as Map<String, dynamic>;
    final holder = data['holder'] as Map<String, dynamic>?;
    final events = (data['events'] ?? []) as List<dynamic>;

    final assetTag = (asset['asset_tag'] ?? '').toString();
    final assetId = (asset['id'] ?? 0) as int;
    final healthStatus = (asset['health_status'] ?? 'ok').toString();
    final healthDesc = (asset['health_desc'] ?? '').toString();
    final conditionNote = (asset['condition_note'] ?? '').toString();
    final serialNo = (asset['serial_number'] ?? '').toString();
    final location = (asset['location'] ?? 'Warehouse Main').toString();

    final itemName = (item['name'] ?? 'Asset').toString();
    final itemCode = (item['item_code'] ?? '').toString();
    final brand = (item['brand'] ?? '').toString();
    final category = (item['category_name'] ?? 'General').toString();
    final imgUrl = AppConfig.resolveImageUrl(item['image_url']?.toString(), activeBaseUrl: _baseUrl);

    Color badgeColor;
    Color badgeBg;
    Color badgeBorder;
    IconData badgeIcon;
    String badgeLabel;

    if (healthStatus == 'ok') {
      badgeColor = AppColors.greenOk;
      badgeBg = AppColors.greenTint;
      badgeBorder = AppColors.greenBorder;
      badgeIcon = Icons.check_circle;
      badgeLabel = globalLanguage.t('ahc_ok_badge');
    } else if (healthStatus == 'maintenance') {
      badgeColor = AppColors.redDanger;
      badgeBg = AppColors.redTint;
      badgeBorder = AppColors.redBorder;
      badgeIcon = Icons.build_circle;
      badgeLabel = globalLanguage.t('ahc_maint_badge');
    } else if (healthStatus == 'checked_out') {
      badgeColor = AppColors.blueInfo;
      badgeBg = AppColors.blueTint;
      badgeBorder = AppColors.blueBorder;
      badgeIcon = Icons.assignment_ind;
      badgeLabel = globalLanguage.t('ahc_loan_badge');
    } else if (healthStatus == 'missing') {
      badgeColor = AppColors.redDanger;
      badgeBg = AppColors.redTint;
      badgeBorder = AppColors.redBorder;
      badgeIcon = Icons.help_outline;
      badgeLabel = globalLanguage.t('ahc_missing_badge');
    } else {
      badgeColor = AppColors.inkSoft;
      badgeBg = AppColors.surfaceSubtle;
      badgeBorder = AppColors.line;
      badgeIcon = Icons.archive_outlined;
      badgeLabel = globalLanguage.t('ahc_retired_badge');
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 84),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // ─── Health Status Hero Card ───
          Container(
            decoration: BoxDecoration(
              color: badgeBg,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: badgeBorder, width: 1.5),
            ),
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(badgeIcon, color: badgeColor, size: 36),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        badgeLabel,
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: badgeColor),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        healthDesc,
                        style: TextStyle(fontSize: 12, color: badgeColor.withOpacity(0.85), height: 1.3),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // ─── Asset Item Summary Card ───
          Container(
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.line),
              boxShadow: [
                BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 8, offset: const Offset(0, 2)),
              ],
            ),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    AppItemImage(imageUrl: imgUrl, width: 68, height: 68, borderRadius: BorderRadius.circular(10)),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: AppColors.charcoal2,
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  assetTag,
                                  style: const TextStyle(fontSize: 12, color: AppColors.amberOnDark, fontWeight: FontWeight.bold, letterSpacing: 0.5),
                                ),
                              ),
                              const Spacer(),
                              IconButton(
                                icon: const Icon(Icons.copy, size: 16, color: AppColors.inkLight),
                                tooltip: 'Copy Tag',
                                onPressed: () {
                                  Clipboard.setData(ClipboardData(text: assetTag));
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(content: Text('Asset tag copied!'), duration: Duration(seconds: 1)),
                                  );
                                },
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(
                            itemName,
                            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.ink),
                          ),
                          if (brand.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(brand, style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontWeight: FontWeight.w500)),
                          ],
                          const SizedBox(height: 2),
                          Text('$category • $itemCode', style: const TextStyle(fontSize: 11, color: AppColors.inkLight)),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                const Divider(height: 1, color: AppColors.line),
                const SizedBox(height: 12),

                // Details Grid
                Row(
                  children: [
                    Expanded(
                      child: _buildDetailCell(Icons.place, globalLanguage.t('isc_location_label'), location),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _buildDetailCell(
                        Icons.tag,
                        globalLanguage.t('ahc_serial_label'),
                        serialNo.isNotEmpty ? serialNo : 'N/A',
                      ),
                    ),
                  ],
                ),
                if (holder != null) ...[
                  const SizedBox(height: 10),
                  _buildDetailCell(
                    Icons.person,
                    globalLanguage.t('ahc_holder_label'),
                    '${holder['name'] ?? ''} (${holder['employee_id'] ?? ''})',
                  ),
                ],
                if (asset['is_onboard_truck'] == true && asset['assigned_truck'] != null) ...[
                  const SizedBox(height: 10),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.greenTint,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: AppColors.greenBorder),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.local_shipping, size: 20, color: AppColors.greenOk),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                globalLanguage.choice('Kit ng Sasakyan (Permanent Onboard)', 'Vehicle Kit (Permanent Onboard)'),
                                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.greenOk),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                '${asset['assigned_truck']['plate_number'] ?? ''} • ${asset['assigned_truck']['model'] ?? ''}',
                                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                globalLanguage.choice('Hindi nag-eexpire — permanenteng gamit sa truck.', 'Never expires — permanent vehicle equipment.'),
                                style: const TextStyle(fontSize: 10.5, color: AppColors.inkSoft),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
                if (conditionNote.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.paper,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: AppColors.line),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          globalLanguage.isTagalog ? 'Huling Puna / Kondisyon:' : 'Condition Note:',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          conditionNote,
                          style: const TextStyle(fontSize: 12, color: AppColors.ink, fontStyle: FontStyle.italic),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 14),

          // ─── Staff Actions ───
          if (healthStatus == 'maintenance') ...[
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.greenOk,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  elevation: 2,
                ),
                icon: const Icon(Icons.check_circle, size: 20),
                label: Text(globalLanguage.t('ahc_mark_repaired_btn'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                onPressed: () => _confirmMarkRepaired(assetTag, assetId),
              ),
            ),
            const SizedBox(height: 8),
          ],
          if (healthStatus != 'retired') ...[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.redDanger,
                  side: const BorderSide(color: AppColors.redDanger, width: 1.5),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                icon: const Icon(Icons.warning_amber_rounded, size: 20),
                label: Text(globalLanguage.t('ahc_report_damage_btn'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                onPressed: () => _showReportDamageSheet(assetTag, assetId),
              ),
            ),
            const SizedBox(height: 14),
          ],

          // ─── Timeline / History ───
          if (events.isNotEmpty) ...[
            Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppColors.line),
              ),
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.history, size: 16, color: AppColors.amber),
                      const SizedBox(width: 6),
                      Text(globalLanguage.t('ahc_history_title'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.inkSoft)),
                    ],
                  ),
                  const SizedBox(height: 10),
                  ...events.map((e) {
                    final ev = e as Map<String, dynamic>;
                    final evType = (ev['event_type'] ?? '').toString();
                    Color dotColor = AppColors.inkLight;
                    if (evType.contains('damage')) {
                      dotColor = AppColors.redDanger;
                    } else if (evType.contains('repair') || evType.contains('maintenance_complete')) {
                      dotColor = AppColors.greenOk;
                    } else if (evType.contains('checkout')) {
                      dotColor = AppColors.blueInfo;
                    }

                    return Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            width: 8, height: 8,
                            margin: const EdgeInsets.only(top: 5, right: 10),
                            decoration: BoxDecoration(shape: BoxShape.circle, color: dotColor),
                          ),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  ev['note']?.toString() ?? evType,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink),
                                ),
                                Text(
                                  '${ev['created_at'] ?? ''} • ${ev['actor_name'] ?? 'Staff'}',
                                  style: const TextStyle(fontSize: 10, color: AppColors.inkLight),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],

          // ─── Scan Another Button ───
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.amber,
                side: const BorderSide(color: AppColors.amber, width: 1.5),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              icon: const Icon(Icons.qr_code_scanner, size: 20),
              label: Text(globalLanguage.t('isc_scan_another'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              onPressed: _reset,
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  Widget _buildDetailCell(IconData icon, String label, String value) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: AppColors.paper,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 14, color: AppColors.inkLight),
              const SizedBox(width: 4),
              Flexible(child: Text(label, style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.inkLight), overflow: TextOverflow.ellipsis)),
            ],
          ),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink), maxLines: 2, overflow: TextOverflow.ellipsis),
        ],
      ),
    );
  }

  Future<void> _submitAssetAction(String assetTag, int assetId, String action, String note) async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/asset_check.php');
      final body = jsonEncode({
        'action': action,
        'tag': assetTag,
        'asset_id': assetId,
        'condition_note': note,
      });
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user, isJson: true),
        body: body,
      ).timeout(const Duration(seconds: 10));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true && data['data'] != null) {
          HapticFeedback.mediumImpact();
          if (mounted) {
            setState(() {
              _assetResult = data['data'];
            });
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(data['message'] ?? (action == 'report_damage' ? 'Damage reported.' : 'Asset repaired.')),
                backgroundColor: AppColors.greenOk,
                duration: const Duration(seconds: 2),
              ),
            );
          }
        } else {
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(data['error'] ?? 'Action failed.'),
                backgroundColor: AppColors.redDanger,
              ),
            );
          }
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Server error (${res.statusCode})'),
              backgroundColor: AppColors.redDanger,
            ),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Network error: $e'),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    }
    if (mounted) setState(() => _isLoading = false);
  }

  void _showReportDamageSheet(String assetTag, int assetId) {
    final noteCtrl = TextEditingController();
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return Container(
          padding: EdgeInsets.only(
            top: 20,
            left: 20,
            right: 20,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    globalLanguage.t('ahc_report_damage_btn'),
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.redDanger),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, size: 20, color: AppColors.inkSoft),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                'Asset Tag: $assetTag',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: noteCtrl,
                maxLines: 3,
                style: const TextStyle(fontSize: 14),
                decoration: InputDecoration(
                  hintText: globalLanguage.t('ahc_condition_note_hint'),
                  contentPadding: const EdgeInsets.all(12),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.redDanger, width: 2)),
                ),
              ),
              const SizedBox(height: 16),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.redDanger,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () {
                  final text = noteCtrl.text.trim();
                  if (text.isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(globalLanguage.isTagalog ? 'Ilagay ang deskripsyon ng sira.' : 'Please enter defect description.'),
                        backgroundColor: AppColors.redDanger,
                      ),
                    );
                    return;
                  }
                  Navigator.pop(ctx);
                  _submitAssetAction(assetTag, assetId, 'report_damage', text);
                },
                child: Text(globalLanguage.t('ahc_submit_report'), style: const TextStyle(fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        );
      },
    );
  }

  void _confirmMarkRepaired(String assetTag, int assetId) {
    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: Text(globalLanguage.t('ahc_mark_repaired_btn'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.greenOk)),
          content: Text(
            globalLanguage.isTagalog
                ? 'I-clear at i-mark ang asset $assetTag bilang maayos at available muli?'
                : 'Mark asset $assetTag as repaired and available for service?',
            style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.greenOk,
                foregroundColor: Colors.white,
              ),
              onPressed: () {
                Navigator.pop(ctx);
                _submitAssetAction(assetTag, assetId, 'mark_repaired', 'Repaired and cleared for service via mobile inspection.');
              },
              child: Text(globalLanguage.t('confirm')),
            ),
          ],
        );
      },
    );
  }
}

class InventoryStockScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const InventoryStockScreen({super.key, required this.user});

  @override
  State<InventoryStockScreen> createState() => _InventoryStockScreenState();
}

class _InventoryStockScreenState extends State<InventoryStockScreen> {
  bool _isLoading = true;
  List<dynamic> _items = [];
  List<dynamic> _categories = [];
  String _searchQuery = '';
  String _selectedCategory = 'All';

  @override
  void initState() {
    super.initState();
    _fetchCatalog();
  }

  Future<void> _fetchCatalog() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final isOfficeStaff = (widget.user['position'] ?? '').toString().toLowerCase() == 'office_staff';
      final url = Uri.parse('$baseUrl/catalog.php?user_id=${widget.user['id']}&position=${widget.user['position'] ?? ''}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        var items = (payload['items'] as List<dynamic>?) ?? [];
        if (isOfficeStaff) {
          items = items.where((it) => (it['category_name'] ?? '') == 'Office Supplies').toList();
        }
        if (mounted) {
          setState(() {
            _items = items;
            _categories = (payload['categories'] as List<dynamic>?) ?? [];
          });
        }
      }
    } catch (_) {}
    if (mounted) setState(() => _isLoading = false);
  }

  List<dynamic> get _filteredItems {
    return _items.where((it) {
      final name = (it['name'] ?? '').toString().toLowerCase();
      final code = (it['item_code'] ?? '').toString().toLowerCase();
      final brand = (it['brand'] ?? '').toString().toLowerCase();
      final location = (it['location'] ?? '').toString().toLowerCase();
      final category = (it['category_name'] ?? '').toString();

      final matchesQuery = _searchQuery.isEmpty ||
          name.contains(_searchQuery.toLowerCase()) ||
          code.contains(_searchQuery.toLowerCase()) ||
          brand.contains(_searchQuery.toLowerCase()) ||
          location.contains(_searchQuery.toLowerCase());

      final isAllCat = _selectedCategory == 'All' || _selectedCategory == globalLanguage.t('cat_all');
      final matchesCat = isAllCat || category == _selectedCategory;
      return matchesQuery && matchesCat;
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final filtered = _filteredItems;

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.t('stock_title'),
        user: widget.user,
      ),
      body: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            color: AppColors.surface,
            child: TextField(
              style: const TextStyle(fontSize: 13),
              decoration: InputDecoration(
                hintText: globalLanguage.isTagalog ? 'Maghanap ng gamit, code, tatak, o stall...' : 'Search items, code, brand, or stall...',
                prefixIcon: const Icon(Icons.search, size: 20, color: AppColors.inkSoft),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.qr_code_scanner, color: AppColors.amber),
                  tooltip: globalLanguage.isTagalog ? 'Buksan ang Smart Scanner' : 'Open Smart Scanner',
                  onPressed: () async {
                    final nav = Navigator.of(context);
                    final code = await nav.push<String>(
                      MaterialPageRoute(builder: (_) => const QrCameraScannerModal()),
                    );
                    if (code != null && code.trim().isNotEmpty && mounted) {
                      final clean = code.trim();
                      String tokenCandidate = clean;
                      final uri = Uri.tryParse(clean);
                      if (uri != null && uri.queryParameters.containsKey('token')) {
                        tokenCandidate = uri.queryParameters['token']!;
                      }
                      final isReqToken = (RegExp(r'^[0-9a-fA-F]{32}').hasMatch(tokenCandidate)) ||
                          tokenCandidate.toUpperCase().startsWith('REQ-') ||
                          tokenCandidate.toUpperCase().startsWith('REQ#') ||
                          tokenCandidate.toLowerCase().contains('token=');

                      if (isReqToken) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(
                              globalLanguage.choice(
                                'QR ng Driver ito! Binubuksan ang Release Verification...',
                                'Driver Request QR detected! Opening Release Verification...',
                              ),
                            ),
                            backgroundColor: AppColors.greenOk,
                            duration: const Duration(seconds: 2),
                          ),
                        );
                        nav.push(
                          MaterialPageRoute(
                            builder: (_) => InventoryVerifyReleaseScreen(
                              user: widget.user,
                              initialToken: tokenCandidate,
                            ),
                          ),
                        );
                      } else {
                        nav.push(
                          MaterialPageRoute(
                            builder: (_) => ItemStockCheckScreen(
                              user: widget.user,
                              initialCode: clean,
                            ),
                          ),
                        );
                      }
                    }
                  },
                ),
                contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(6), borderSide: const BorderSide(color: AppColors.line)),
              ),
              onChanged: (val) => setState(() => _searchQuery = val),
            ),
          ),
          Container(
            height: 42,
            color: AppColors.surfaceSubtle,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              children: [
                _buildCatPill(globalLanguage.t('cat_all'), isAll: true),
                ..._categories.map((c) => _buildCatPill(c['name'] ?? '')),
              ],
            ),
          ),
          const Divider(height: 1, color: AppColors.line),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _fetchCatalog,
              color: AppColors.amber,
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
                  : filtered.isEmpty
                      ? Center(
                          child: Text(globalLanguage.isTagalog ? 'Walang nahanap na item.' : 'No items found.', style: const TextStyle(color: AppColors.inkSoft)),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(12, 12, 12, 84),
                          itemCount: filtered.length,
                          itemBuilder: (ctx, idx) {
                            final item = filtered[idx] as Map<String, dynamic>;
                            final stock = item['quantity_on_hand'] ?? 0;
                            final isLow = stock > 0 && stock <= 5;
                            final isOut = stock <= 0;
                            final location = item['location'] ?? 'Bodega';
                            final loanCount = item['loan_count'] ?? 0;

                            return Card(
                              margin: const EdgeInsets.only(bottom: 10),
                              clipBehavior: Clip.antiAlias,
                              child: InkWell(
                                onTap: () {
                                  Navigator.push(
                                    context,
                                    MaterialPageRoute(
                                      builder: (_) => ItemStockCheckScreen(
                                        user: widget.user,
                                        initialCode: item['item_code']?.toString(),
                                      ),
                                    ),
                                  );
                                },
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Row(
                                    children: [
                                      AppItemImage(imageUrl: item['image_url'], width: 50, height: 50),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item['name'] ?? 'Item',
                                              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink),
                                            ),
                                            const SizedBox(height: 2),
                                            Row(
                                              children: [
                                                const Icon(Icons.place_outlined, size: 14, color: AppColors.amber),
                                                const SizedBox(width: 4),
                                                Text(
                                                  location,
                                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: AppColors.amberDim),
                                                ),
                                              ],
                                            ),
                                            const SizedBox(height: 2),
                                            Text(
                                              'Code: ${item['item_code'] ?? "N/A"} • ${item['category_name'] ?? "General"}',
                                              style: const TextStyle(fontSize: 11, color: AppColors.inkLight),
                                            ),
                                            if (loanCount > 0) ...[
                                              const SizedBox(height: 3),
                                              Text(
                                                'Kasalukuyang Hiniram: $loanCount ${item['unit']}',
                                                style: const TextStyle(fontSize: 11, color: AppColors.blueInfo, fontWeight: FontWeight.bold),
                                              ),
                                            ],
                                          ],
                                        ),
                                      ),
                                      const SizedBox(width: 10),
                                      Column(
                                        crossAxisAlignment: CrossAxisAlignment.end,
                                        children: [
                                          Text(
                                            '$stock',
                                            style: TextStyle(
                                              fontSize: 20,
                                              fontWeight: FontWeight.bold,
                                              color: isOut ? AppColors.redDanger : (isLow ? AppColors.amber : AppColors.ink),
                                            ),
                                          ),
                                          Text(
                                            item['unit'] ?? 'pc',
                                            style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                                          ),
                                          const SizedBox(height: 4),
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                                            decoration: BoxDecoration(
                                              color: isOut
                                                  ? AppColors.redTint
                                                  : isLow
                                                      ? AppColors.amberTint
                                                      : AppColors.greenTint,
                                              borderRadius: BorderRadius.circular(4),
                                              border: Border.all(
                                                color: isOut
                                                    ? AppColors.redBorder
                                                    : isLow
                                                        ? AppColors.amberBorder
                                                        : AppColors.greenBorder,
                                              ),
                                            ),
                                            child: Text(
                                              isOut ? 'Out of Stock' : (isLow ? 'Low Stock' : 'May Stock'),
                                              style: TextStyle(
                                                fontSize: 10,
                                                fontWeight: FontWeight.bold,
                                                color: isOut ? AppColors.redDanger : (isLow ? AppColors.amber : AppColors.greenOk),
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildCatPill(String cat, {bool isAll = false}) {
    final isSelected = isAll
        ? (_selectedCategory == 'All' || _selectedCategory == globalLanguage.t('cat_all'))
        : (_selectedCategory == cat);
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: InkWell(
        onTap: () => setState(() => _selectedCategory = isAll ? 'All' : cat),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
          decoration: BoxDecoration(
            color: isSelected ? AppColors.charcoal2 : Colors.transparent,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            cat,
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
              color: isSelected ? AppColors.amberOnDark : AppColors.inkSoft,
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// ---------------------------------------------------------
// Field Supervisor Screen: Approvals Queue & History
// (100% Web Parity with requisition/dashboard.php & approval tabs)
// ---------------------------------------------------------
class SupervisorApprovalsScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const SupervisorApprovalsScreen({super.key, required this.user});

  @override
  State<SupervisorApprovalsScreen> createState() => _SupervisorApprovalsScreenState();
}

class _SupervisorApprovalsScreenState extends State<SupervisorApprovalsScreen> {
  bool _isLoading = false;
  List<dynamic> _requests = [];
  String _activeTab = 'pending'; // 'pending', 'approved', 'declined', 'all'
  String _searchQuery = '';
  final TextEditingController _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _fetchRequests();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _fetchRequests({String? tab}) async {
    final targetTab = tab ?? _activeTab;
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php?user_id=${widget.user['id']}&role=${widget.user['role']}&tab=$targetTab&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (mounted) {
          setState(() {
            _requests = payload is List ? payload : [];
          });
        }
      } else {
        debugPrint('[SupervisorApprovals._fetchRequests] HTTP ${res.statusCode}: ${res.body}');
        if (mounted && res.statusCode == 401) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(globalLanguage.choice('Na-expire o invalid na ang session mo — mag-login ulit.', 'Session expired or invalid — please log in again.'))),
          );
        }
      }
    } catch (e) {
      debugPrint('[SupervisorApprovals._fetchRequests] failed: $e');
    }
    if (mounted) setState(() => _isLoading = false);
  }

  Future<void> _decide(int reqId, String decision) async {
    String? note;
    if (decision == 'declined') {
      final noteCtrl = TextEditingController();
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(
            globalLanguage.isTagalog ? 'Dahilan ng Pagtanggi' : 'Reason for Rejection',
            style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
          ),
          content: TextField(
            controller: noteCtrl,
            maxLines: 3,
            decoration: InputDecoration(
              hintText: globalLanguage.isTagalog
                  ? 'Ipasok ang dahilan kung bakit tinatanggihan ang kahilingan...'
                  : 'Enter reason for rejecting requisition...',
              border: const OutlineInputBorder(),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.redDanger, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.t('reject')),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
      note = noteCtrl.text.trim();
    } else {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(
            globalLanguage.isTagalog ? 'Aprubahan ang Requisition?' : 'Approve Requisition?',
            style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
          ),
          content: Text(
            globalLanguage.isTagalog
                ? 'Kumpirmahin na inaprubahan mo ang kahilingang ito para sa fleet operation.'
                : 'Confirm approval for this requisition in fleet operation.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.greenOk, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.t('approve')),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
    }

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'decide',
          'requisition_id': reqId,
          'decision': decision,
          'decision_note': note ?? '',
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              globalLanguage.isTagalog
                  ? 'Requisition #$reqId matagumpay na na-${decision == "approved" ? "aprubahan" : "tanggihan"}.'
                  : 'Requisition #$reqId successfully ${decision == "approved" ? "approved" : "rejected"}.',
            ),
            backgroundColor: decision == 'approved' ? AppColors.greenOk : AppColors.redDanger,
          ),
        );
        _fetchRequests();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(data['error'] ?? data['message'] ?? 'Nagka-error.'), backgroundColor: AppColors.redDanger),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Server connection error.'), backgroundColor: AppColors.redDanger),
      );
    }
  }

  Widget _buildTabBtn(String tabKey, String labelTagalog, String labelEng, IconData icon) {
    final isSelected = _activeTab == tabKey;
    return Expanded(
      child: InkWell(
        onTap: () {
          setState(() => _activeTab = tabKey);
          _fetchRequests(tab: tabKey);
        },
        borderRadius: BorderRadius.circular(8),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: isSelected ? AppColors.amber : AppColors.surface,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: isSelected ? AppColors.amber : AppColors.line),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 14, color: isSelected ? Colors.white : AppColors.inkSoft),
              const SizedBox(width: 4),
              Text(
                globalLanguage.choice(labelTagalog, labelEng),
                style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                  color: isSelected ? Colors.white : AppColors.inkSoft,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final q = _searchQuery.toLowerCase().trim();
    final filtered = _requests.where((req) {
      if (q.isEmpty) return true;
      final id = '${req['id']}'.toLowerCase();
      final requester = '${req['requester_name'] ?? ''}'.toLowerCase();
      final plate = '${req['plate_number'] ?? req['truck_plate_snapshot'] ?? ''}'.toLowerCase();
      final purpose = '${req['purpose'] ?? ''}'.toLowerCase();
      return id.contains(q) || requester.contains(q) || plate.contains(q) || purpose.contains(q);
    }).toList();

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.choice('Aprubasyon ng Requisition', 'Requisition Approvals'),
        user: widget.user,
      ),
      body: RefreshIndicator(
        onRefresh: _fetchRequests,
        color: AppColors.amber,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
          children: [
            // Segmented Filter Tabs
            Row(
              children: [
                _buildTabBtn('pending', 'Naghihintay', 'Pending', Icons.hourglass_top_rounded),
                const SizedBox(width: 6),
                _buildTabBtn('approved', 'Inaprubahan', 'Approved', Icons.check_circle_outline),
                const SizedBox(width: 6),
                _buildTabBtn('declined', 'Tinanggihan', 'Declined', Icons.cancel_outlined),
                const SizedBox(width: 6),
                _buildTabBtn('all', 'Lahat', 'All', Icons.list_alt_rounded),
              ],
            ),
            const SizedBox(height: 12),

            // Search Bar
            Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.line),
              ),
              child: TextField(
                controller: _searchCtrl,
                onChanged: (val) => setState(() => _searchQuery = val),
                decoration: InputDecoration(
                  hintText: globalLanguage.choice('Maghanap sa REQ #, drayber, o plate...', 'Search REQ #, driver, or plate...'),
                  hintStyle: const TextStyle(color: AppColors.inkLight, fontSize: 12.5),
                  prefixIcon: const Icon(Icons.search, size: 18, color: AppColors.inkSoft),
                  suffixIcon: _searchQuery.isNotEmpty
                      ? IconButton(
                          icon: const Icon(Icons.clear, size: 16, color: AppColors.inkSoft),
                          onPressed: () {
                            _searchCtrl.clear();
                            setState(() => _searchQuery = '');
                          },
                        )
                      : null,
                  border: InputBorder.none,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
                ),
              ),
            ),
            const SizedBox(height: 12),

            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  globalLanguage.choice('Talaan ng Kahilingan', 'Requisition Queue'),
                  style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: filtered.isEmpty ? AppColors.surfaceSubtle : AppColors.amberTint,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: filtered.isEmpty ? AppColors.line : AppColors.amberBorder),
                  ),
                  child: Text(
                    '${filtered.length} ${globalLanguage.choice('kahilingan', 'requests')}',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: filtered.isEmpty ? AppColors.inkSoft : AppColors.amber,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            if (_isLoading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 40),
                child: Center(child: CircularProgressIndicator(color: AppColors.amber)),
              )
            else if (filtered.isEmpty)
              Card(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 20),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.inbox_outlined, size: 48, color: AppColors.inkLight),
                      const SizedBox(height: 12),
                      Text(
                        globalLanguage.choice('Walang Nahanap na Requisition', 'No Requisitions Found'),
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.ink),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        globalLanguage.choice(
                          'Walang tumutugma sa kasalukuyang tab o paghahanap.',
                          'No records match the current tab filter or search query.',
                        ),
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                      ),
                    ],
                  ),
                ),
              )
            else
              ...filtered.map((req) {
                final reqId = req['id'];
                final requester = req['requester_name'] ?? 'Personnel';
                final truck = req['plate_number'] ?? req['truck_plate_snapshot'] ?? 'Walang Truck';
                final purpose = req['purpose'] ?? 'General Requisition';
                final items = (req['items'] as List<dynamic>?) ?? [];
                final isUrgent = req['manual_urgent'] == 1 || req['manual_urgent'] == true;
                final priorityScore = req['priority_score'] ?? '0.00';
                final priorityStock = (req['priority_stock'] as num?)?.toInt() ?? 0;
                final priorityDemand = (req['priority_demand'] as num?)?.toInt() ?? 0;
                final priorityTrust = (req['priority_trust'] as num?)?.toInt() ?? 0;
                final scoreVal = double.tryParse(priorityScore.toString()) ?? 0.0;
                final status = (req['status'] ?? 'pending').toString().toUpperCase();
                final decisionNote = req['decision_note']?.toString() ?? '';

                Color priorityBg = AppColors.surfaceSubtle;
                Color priorityBorder = AppColors.line;
                Color priorityColor = AppColors.inkSoft;
                if (isUrgent || scoreVal >= 70) {
                  priorityBg = AppColors.redTint;
                  priorityBorder = AppColors.redBorder;
                  priorityColor = AppColors.redDanger;
                } else if (scoreVal >= 40) {
                  priorityBg = AppColors.amberTint;
                  priorityBorder = AppColors.amberBorder;
                  priorityColor = AppColors.amber;
                } else if (scoreVal > 0) {
                  priorityBg = AppColors.blueTint;
                  priorityBorder = AppColors.blueBorder;
                  priorityColor = AppColors.blueInfo;
                }

                Color statusBg = AppColors.surfaceSubtle;
                Color statusColor = AppColors.inkSoft;
                Color statusBorder = AppColors.line;
                if (status == 'PENDING') {
                  statusBg = AppColors.amberTint;
                  statusColor = AppColors.amber;
                  statusBorder = AppColors.amberBorder;
                } else if (status == 'APPROVED') {
                  statusBg = AppColors.greenTint;
                  statusColor = AppColors.greenOk;
                  statusBorder = AppColors.greenBorder;
                } else if (status == 'DECLINED') {
                  statusBg = AppColors.redTint;
                  statusColor = AppColors.redDanger;
                  statusBorder = AppColors.redBorder;
                } else if (status == 'RELEASED') {
                  statusBg = AppColors.blueTint;
                  statusColor = AppColors.blueInfo;
                  statusBorder = AppColors.blueBorder;
                }

                return Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: isUrgent ? AppColors.redBorder : AppColors.line,
                      width: isUrgent ? 1.5 : 1,
                    ),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Row(
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: AppColors.charcoal2,
                                    borderRadius: BorderRadius.circular(4),
                                  ),
                                  child: Text(
                                    'REQ #$reqId',
                                    style: const TextStyle(color: AppColors.amberOnDark, fontSize: 11, fontWeight: FontWeight.bold),
                                  ),
                                ),
                                const SizedBox(width: 6),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: statusBg,
                                    borderRadius: BorderRadius.circular(4),
                                    border: Border.all(color: statusBorder),
                                  ),
                                  child: Text(
                                    status,
                                    style: TextStyle(color: statusColor, fontSize: 10, fontWeight: FontWeight.bold),
                                  ),
                                ),
                                if (isUrgent) ...[
                                  const SizedBox(width: 6),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: AppColors.redTint,
                                      borderRadius: BorderRadius.circular(4),
                                      border: Border.all(color: AppColors.redBorder),
                                    ),
                                    child: const Text('URGENT', style: TextStyle(color: AppColors.redDanger, fontSize: 10, fontWeight: FontWeight.bold)),
                                  ),
                                ],
                              ],
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                              decoration: BoxDecoration(
                                color: priorityBg,
                                borderRadius: BorderRadius.circular(4),
                                border: Border.all(color: priorityBorder),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(Icons.bolt_rounded, size: 12, color: priorityColor),
                                  const SizedBox(width: 3),
                                  Text(
                                    'Priority: $priorityScore',
                                    style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: priorityColor),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const Icon(Icons.person, size: 15, color: AppColors.inkSoft),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(requester, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: AppColors.ink)),
                            ),
                            const Icon(Icons.local_shipping, size: 15, color: AppColors.inkSoft),
                            const SizedBox(width: 4),
                            Text(truck, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink)),
                          ],
                        ),
                        // Fleet Requisition Type & Status Badges
                        if (req['truck_id'] != null || (req['truck_plate_snapshot'] != null && req['truck_plate_snapshot'].toString().isNotEmpty)) ...[
                          const SizedBox(height: 5),
                          Wrap(
                            spacing: 5,
                            runSpacing: 4,
                            children: [
                              if (req['is_maintenance_request'] == 1 || req['is_maintenance_request'] == true || req['is_maintenance_request'] == '1') ...[
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: AppColors.blueTint,
                                    borderRadius: BorderRadius.circular(4),
                                    border: Border.all(color: AppColors.blueBorder),
                                  ),
                                  child: const Text('🔧 Pyesa / Repair', style: TextStyle(color: AppColors.blueInfo, fontSize: 10, fontWeight: FontWeight.bold)),
                                ),
                                if ('${req['truck_status']}'.toLowerCase() == 'available')
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: AppColors.amberTint,
                                      borderRadius: BorderRadius.circular(4),
                                      border: Border.all(color: AppColors.amberBorder),
                                    ),
                                    child: const Text('⚠️ Truck Available', style: TextStyle(color: AppColors.amber, fontSize: 10, fontWeight: FontWeight.bold)),
                                  ),
                              ] else ...[
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: AppColors.surfaceSubtle,
                                    borderRadius: BorderRadius.circular(4),
                                    border: Border.all(color: AppColors.line),
                                  ),
                                  child: const Text('🚛 Gamit sa Byahe', style: TextStyle(color: AppColors.inkSoft, fontSize: 10, fontWeight: FontWeight.w600)),
                                ),
                                if ('${req['truck_status']}'.toLowerCase() == 'under_maintenance')
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: AppColors.redTint,
                                      borderRadius: BorderRadius.circular(4),
                                      border: Border.all(color: AppColors.redBorder),
                                    ),
                                    child: const Text('⛔ Truck In Repair', style: TextStyle(color: AppColors.redDanger, fontSize: 10, fontWeight: FontWeight.bold)),
                                  ),
                              ],
                            ],
                          ),
                        ],
                        if (purpose.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text('Layunin: $purpose', style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
                        ],
                        if (scoreVal > 0 || priorityStock > 0 || priorityDemand > 0 || priorityTrust > 0) ...[
                          const SizedBox(height: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
                            decoration: BoxDecoration(
                              color: AppColors.paper,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.line),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.analytics_outlined, size: 14, color: AppColors.inkSoft),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    globalLanguage.choice(
                                      'MCDA Batayan: 📦 Stock $priorityStock% • 🚨 Demand $priorityDemand% • 🤝 Pagsasauli $priorityTrust%',
                                      'MCDA Factors: 📦 Stock $priorityStock% • 🚨 Demand $priorityDemand% • 🤝 Return Trust $priorityTrust%',
                                    ),
                                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.ink),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        const Divider(color: AppColors.line, height: 16),
                        Text(
                          globalLanguage.choice('Mga Hinihiling na Gamit (${items.length}):', 'Requested Items (${items.length}):'),
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        const SizedBox(height: 4),
                        ...items.map((it) {
                          final itName = it['item_name'] ?? 'Item';
                          final qty = it['quantity_requested'] ?? 1;
                          final unit = it['unit'] ?? 'pc';
                          final isBorrow = it['is_borrowable'] == 1 || it['is_borrowable'] == true;
                          return Padding(
                            padding: const EdgeInsets.symmetric(vertical: 2),
                            child: Row(
                              children: [
                                const Text('• ', style: TextStyle(color: AppColors.amber, fontWeight: FontWeight.bold)),
                                Expanded(child: Text(itName, style: const TextStyle(fontSize: 12, color: AppColors.ink))),
                                Text(
                                  '$qty $unit ${isBorrow ? "(HIRAM)" : ""}',
                                  style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
                                ),
                              ],
                            ),
                          );
                        }),
                        if (decisionNote.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: status == 'DECLINED' ? AppColors.redTint : AppColors.greenTint,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: status == 'DECLINED' ? AppColors.redBorder : AppColors.greenBorder),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Icon(
                                  status == 'DECLINED' ? Icons.info_outline : Icons.chat_bubble_outline,
                                  size: 14,
                                  color: status == 'DECLINED' ? AppColors.redDanger : AppColors.greenOk,
                                ),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    '${status == "DECLINED" ? (globalLanguage.isTagalog ? "Dahilan: " : "Reason: ") : "Note: "}$decisionNote',
                                    style: TextStyle(
                                      fontSize: 11.5,
                                      color: status == 'DECLINED' ? AppColors.redDanger : AppColors.greenOk,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        if (status == 'PENDING') ...[
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              Expanded(
                                child: ElevatedButton.icon(
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: AppColors.greenOk,
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(vertical: 10),
                                  ),
                                  icon: const Icon(Icons.check, size: 16),
                                  label: Text(globalLanguage.t('approve'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                                  onPressed: () => _decide(reqId, 'approved'),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(
                                    foregroundColor: AppColors.redDanger,
                                    side: const BorderSide(color: AppColors.redBorder),
                                    padding: const EdgeInsets.symmetric(vertical: 10),
                                  ),
                                  icon: const Icon(Icons.close, size: 16),
                                  label: Text(globalLanguage.t('reject'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                                  onPressed: () => _decide(reqId, 'declined'),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Field Supervisor & Fleet Screen: Fleet Trucks Management
// (100% Web Parity with inventory/trucks.php)
// ---------------------------------------------------------
class FleetTrucksScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const FleetTrucksScreen({super.key, required this.user});

  @override
  State<FleetTrucksScreen> createState() => _FleetTrucksScreenState();
}

class _FleetTrucksScreenState extends State<FleetTrucksScreen> {
  bool _isLoading = false;
  Map<String, dynamic> _metrics = {
    'total': 0,
    'available': 0,
    'on_trip': 0,
    'under_maintenance': 0,
  };
  List<dynamic> _trucks = [];
  String _selectedFilter = 'all'; // 'all', 'available', 'on_trip', 'under_maintenance'
  String _searchQuery = '';
  final TextEditingController _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _fetchTrucks();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _fetchTrucks() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/trucks.php?user_id=${widget.user['id']}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true && data['data'] != null) {
          final payload = data['data'];
          if (mounted) {
            setState(() {
              _metrics = (payload['metrics'] as Map<String, dynamic>?) ?? _metrics;
              _trucks = (payload['trucks'] as List<dynamic>?) ?? [];
            });
          }
        }
      } else {
        debugPrint('[FleetTrucksScreen._fetchTrucks] HTTP ${res.statusCode}: ${res.body}');
      }
    } catch (e) {
      debugPrint('[FleetTrucksScreen._fetchTrucks] error: $e');
    }
    if (mounted) setState(() => _isLoading = false);
  }

  String _statusLabel(String status) {
    switch (status) {
      case 'available':
        return globalLanguage.choice('Magagamit', 'Available');
      case 'on_trip':
        return globalLanguage.choice('Bumabyahe', 'On Trip');
      case 'under_maintenance':
        return globalLanguage.choice('Nasa Maintenance', 'Under Maintenance');
      default:
        return status.toUpperCase();
    }
  }

  Future<void> _updateTruckStatus(Map<String, dynamic> truck, String newStatus) async {
    final truckId = truck['id'];
    final plate = truck['plate_number'] ?? 'Truck';
    final unreturned = (truck['unreturned_loans'] as num?)?.toInt() ?? 0;

    if (newStatus == 'under_maintenance' && unreturned > 0) {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(
            globalLanguage.choice('May Gamit na Hindi Naisasauli', 'Unreturned Equipment Warning'),
            style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.redDanger, fontSize: 16),
          ),
          content: Text(
            globalLanguage.choice(
              'Ang sasakyang $plate ay may $unreturned na gamit na hiniram at hindi pa naisasauli sa warehouse. Sigurado ka bang ilalagay ito sa Maintenance?',
              'Truck $plate currently has $unreturned unreturned borrowed equipment. Are you sure you want to set it Under Maintenance?',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.amber, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.choice('Ituloy Pa Rin', 'Proceed Anyway')),
            ),
          ],
        ),
      );
      if (proceed != true) return;
    }

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/trucks.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'update_status',
          'truck_id': truckId,
          'status': newStatus,
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              globalLanguage.choice(
                'Nai-update ang katayuan ng $plate sa ${_statusLabel(newStatus)}.',
                'Status of $plate updated to ${_statusLabel(newStatus)}.',
              ),
            ),
            backgroundColor: AppColors.greenOk,
          ),
        );
        _fetchTrucks();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(data['error'] ?? data['message'] ?? 'Failed to update truck status.'),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Server connection error.'), backgroundColor: AppColors.redDanger),
      );
    }
  }

  void _showStatusDialog(Map<String, dynamic> truck) {
    final currentStatus = truck['status'] ?? 'available';
    final plate = truck['plate_number'] ?? 'Truck';

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                const Icon(Icons.local_shipping_outlined, color: AppColors.amber, size: 22),
                const SizedBox(width: 8),
                Text(
                  globalLanguage.choice('Baguhin ang Katayuan ($plate)', 'Change Status ($plate)'),
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.ink),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _buildStatusTile(
              ctx,
              statusKey: 'available',
              title: globalLanguage.choice('Magagamit (Available)', 'Available'),
              subtitle: globalLanguage.choice('Handang gamitin para sa operasyon o trip', 'Ready for fleet operations and trips'),
              icon: Icons.check_circle_outline,
              color: AppColors.greenOk,
              isSelected: currentStatus == 'available',
              onTap: () {
                Navigator.pop(ctx);
                _updateTruckStatus(truck, 'available');
              },
            ),
            const SizedBox(height: 8),
            _buildStatusTile(
              ctx,
              statusKey: 'on_trip',
              title: globalLanguage.choice('Bumabyahe (On Trip)', 'On Trip'),
              subtitle: globalLanguage.choice('Kasalukuyang ginagamit sa labas o delivery', 'Currently deployed for field delivery or service'),
              icon: Icons.alt_route_rounded,
              color: AppColors.blueInfo,
              isSelected: currentStatus == 'on_trip',
              onTap: () {
                Navigator.pop(ctx);
                _updateTruckStatus(truck, 'on_trip');
              },
            ),
            const SizedBox(height: 8),
            _buildStatusTile(
              ctx,
              statusKey: 'under_maintenance',
              title: globalLanguage.choice('Nasa Maintenance (Under Maintenance)', 'Under Maintenance'),
              subtitle: globalLanguage.choice('Sumasailalim sa kumpuni, inspeksyon o repair', 'Under repair, preventative maintenance or inspection'),
              icon: Icons.build_circle_outlined,
              color: AppColors.redDanger,
              isSelected: currentStatus == 'under_maintenance',
              onTap: () {
                Navigator.pop(ctx);
                _updateTruckStatus(truck, 'under_maintenance');
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildStatusTile(
    BuildContext ctx, {
    required String statusKey,
    required String title,
    required String subtitle,
    required IconData icon,
    required Color color,
    required bool isSelected,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: isSelected ? color.withOpacity(0.08) : AppColors.surface,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: isSelected ? color : AppColors.line),
        ),
        child: Row(
          children: [
            Icon(icon, color: color, size: 22),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: isSelected ? color : AppColors.ink)),
                  const SizedBox(height: 2),
                  Text(subtitle, style: const TextStyle(fontSize: 11, color: AppColors.inkSoft)),
                ],
              ),
            ),
            if (isSelected) Icon(Icons.check, color: color, size: 18),
          ],
        ),
      ),
    );
  }

  Widget _buildMetricCard({
    required String labelTagalog,
    required String labelEng,
    required int count,
    required IconData icon,
    required Color color,
    required String filterValue,
  }) {
    final isSelected = _selectedFilter == filterValue;
    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _selectedFilter = filterValue),
        borderRadius: BorderRadius.circular(8),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 6),
          decoration: BoxDecoration(
            color: isSelected ? color.withOpacity(0.08) : AppColors.surface,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: isSelected ? color : AppColors.line, width: isSelected ? 1.5 : 1),
          ),
          child: Column(
            children: [
              Icon(icon, size: 18, color: color),
              const SizedBox(height: 4),
              Text(
                '$count',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: color),
              ),
              const SizedBox(height: 2),
              Text(
                globalLanguage.choice(labelTagalog, labelEng),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.w600, color: isSelected ? color : AppColors.inkSoft),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final q = _searchQuery.toLowerCase().trim();
    final filtered = _trucks.where((t) {
      if (_selectedFilter != 'all' && t['status'] != _selectedFilter) {
        return false;
      }
      if (q.isEmpty) return true;
      final plate = '${t['plate_number'] ?? ''}'.toLowerCase();
      final model = '${t['model'] ?? ''}'.toLowerCase();
      final brand = '${t['brand'] ?? ''}'.toLowerCase();
      return plate.contains(q) || model.contains(q) || brand.contains(q);
    }).toList();

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.choice('Pamamahala ng Fleet Trucks', 'Fleet Trucks Management'),
        user: widget.user,
      ),
      body: RefreshIndicator(
        onRefresh: _fetchTrucks,
        color: AppColors.amber,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
          children: [
            // Top Metrics Grid
            Row(
              children: [
                _buildMetricCard(
                  labelTagalog: 'Kabuuan',
                  labelEng: 'Total',
                  count: (_metrics['total'] as num?)?.toInt() ?? 0,
                  icon: Icons.local_shipping_outlined,
                  color: AppColors.ink,
                  filterValue: 'all',
                ),
                const SizedBox(width: 6),
                _buildMetricCard(
                  labelTagalog: 'Magagamit',
                  labelEng: 'Available',
                  count: (_metrics['available'] as num?)?.toInt() ?? 0,
                  icon: Icons.check_circle_outline,
                  color: AppColors.greenOk,
                  filterValue: 'available',
                ),
                const SizedBox(width: 6),
                _buildMetricCard(
                  labelTagalog: 'Bumabyahe',
                  labelEng: 'On Trip',
                  count: (_metrics['on_trip'] as num?)?.toInt() ?? 0,
                  icon: Icons.alt_route_rounded,
                  color: AppColors.blueInfo,
                  filterValue: 'on_trip',
                ),
                const SizedBox(width: 6),
                _buildMetricCard(
                  labelTagalog: 'Maintenance',
                  labelEng: 'Maintenance',
                  count: (_metrics['under_maintenance'] as num?)?.toInt() ?? 0,
                  icon: Icons.build_circle_outlined,
                  color: AppColors.redDanger,
                  filterValue: 'under_maintenance',
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Search Bar
            Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppColors.line),
              ),
              child: TextField(
                controller: _searchCtrl,
                onChanged: (val) => setState(() => _searchQuery = val),
                decoration: InputDecoration(
                  hintText: globalLanguage.choice('Maghanap sa plate # o modelo...', 'Search plate # or model...'),
                  hintStyle: const TextStyle(color: AppColors.inkLight, fontSize: 12.5),
                  prefixIcon: const Icon(Icons.search, size: 18, color: AppColors.inkSoft),
                  suffixIcon: _searchQuery.isNotEmpty
                      ? IconButton(
                          icon: const Icon(Icons.clear, size: 16, color: AppColors.inkSoft),
                          onPressed: () {
                            _searchCtrl.clear();
                            setState(() => _searchQuery = '');
                          },
                        )
                      : null,
                  border: InputBorder.none,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
                ),
              ),
            ),
            const SizedBox(height: 12),

            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  globalLanguage.choice('Mga Sasakyan ng Kompanya', 'Company Fleet Vehicles'),
                  style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: filtered.isEmpty ? AppColors.surfaceSubtle : AppColors.amberTint,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: filtered.isEmpty ? AppColors.line : AppColors.amberBorder),
                  ),
                  child: Text(
                    '${filtered.length} ${globalLanguage.choice('sasakyan', 'trucks')}',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: filtered.isEmpty ? AppColors.inkSoft : AppColors.amber,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            if (_isLoading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 40),
                child: Center(child: CircularProgressIndicator(color: AppColors.amber)),
              )
            else if (filtered.isEmpty)
              Card(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 20),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.no_crash_outlined, size: 48, color: AppColors.inkLight),
                      const SizedBox(height: 12),
                      Text(
                        globalLanguage.choice('Walang Nahanap na Sasakyan', 'No Trucks Found'),
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.ink),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        globalLanguage.choice(
                          'Walang tumutugma sa kasalukuyang filter o paghahanap.',
                          'No vehicles match the selected filter or search terms.',
                        ),
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                      ),
                    ],
                  ),
                ),
              )
            else
              ...filtered.map((truck) {
                final plate = truck['plate_number'] ?? 'N/A';
                final model = truck['model'] ?? '';
                final brand = truck['brand'] ?? '';
                final status = truck['status'] ?? 'available';
                final unreturned = (truck['unreturned_loans'] as num?)?.toInt() ?? 0;
                final repairs = (truck['active_repairs'] as num?)?.toInt() ?? 0;
                final repairIds = '${truck['active_repair_ids'] ?? ''}'.trim();
                final trips = (truck['active_trip_requisitions'] as num?)?.toInt() ?? 0;

                Color statusBg = AppColors.greenTint;
                Color statusColor = AppColors.greenOk;
                Color statusBorder = AppColors.greenBorder;
                IconData statusIcon = Icons.check_circle_outline;

                if (status == 'on_trip') {
                  statusBg = AppColors.blueTint;
                  statusColor = AppColors.blueInfo;
                  statusBorder = AppColors.blueBorder;
                  statusIcon = Icons.alt_route_rounded;
                } else if (status == 'under_maintenance') {
                  statusBg = AppColors.redTint;
                  statusColor = AppColors.redDanger;
                  statusBorder = AppColors.redBorder;
                  statusIcon = Icons.build_circle_outlined;
                }

                return Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: repairs > 0 || unreturned > 0 ? AppColors.amberBorder : AppColors.line,
                      width: 1,
                    ),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Row(
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(
                                    color: AppColors.charcoal,
                                    borderRadius: BorderRadius.circular(4),
                                  ),
                                  child: Text(
                                    plate,
                                    style: const TextStyle(color: AppColors.amberOnDark, fontSize: 13, fontWeight: FontWeight.bold, letterSpacing: 0.5),
                                  ),
                                ),
                              ],
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: statusBg,
                                borderRadius: BorderRadius.circular(4),
                                border: Border.all(color: statusBorder),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(statusIcon, size: 13, color: statusColor),
                                  const SizedBox(width: 4),
                                  Text(
                                    _statusLabel(status),
                                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: statusColor),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          '$brand $model'.trim().isNotEmpty ? '$brand $model'.trim() : globalLanguage.choice('Standard Fleet Vehicle', 'Standard Fleet Vehicle'),
                          style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600, color: AppColors.ink),
                        ),
                        if (unreturned > 0) ...[
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: AppColors.amberTint,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.amberBorder),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.handyman_outlined, size: 14, color: AppColors.amber),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    globalLanguage.choice(
                                      'May $unreturned na hiniram na gamit na hindi pa naisasauli.',
                                      'Has $unreturned unreturned borrowed equipment.',
                                    ),
                                    style: const TextStyle(fontSize: 11.5, color: AppColors.amberDim, fontWeight: FontWeight.w500),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        if (repairs > 0) ...[
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: AppColors.blueTint,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.blueBorder),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.build_circle_outlined, size: 14, color: AppColors.blueInfo),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    globalLanguage.choice(
                                      'May $repairs na kahilingan para sa kumpuni/pyesa ${repairIds.isNotEmpty ? "(Req #$repairIds)" : ""}.',
                                      'Has $repairs vehicle repair/parts request(s) ${repairIds.isNotEmpty ? "(Req #$repairIds)" : ""}.',
                                    ),
                                    style: const TextStyle(fontSize: 11.5, color: AppColors.blueInfo, fontWeight: FontWeight.w600),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          if (status == 'available') ...[
                            const SizedBox(height: 5),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                              decoration: BoxDecoration(
                                color: AppColors.amberTint,
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(color: AppColors.amberBorder),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.info_outline, size: 13, color: AppColors.amber),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Text(
                                      globalLanguage.choice(
                                        'May nakapilang kumpuni pero Available pa ang truck. Suriin kung dapat i-set as Under Maintenance.',
                                        'Repair ticket pending while truck is Available. Check if truck should be set Under Maintenance.',
                                      ),
                                      style: const TextStyle(fontSize: 10.5, color: AppColors.amberDim, fontWeight: FontWeight.w500),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ] else if (status == 'under_maintenance') ...[
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                            decoration: BoxDecoration(
                              color: AppColors.surfaceSubtle,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.line),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.info_outline, size: 13, color: AppColors.inkSoft),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    globalLanguage.choice(
                                      'Naka-Under Maintenance sa bakuran (Walang nakabinbing pyesa ticket).',
                                      'Under Maintenance in yard (No pending parts ticket).',
                                    ),
                                    style: const TextStyle(fontSize: 10.5, color: AppColors.inkSoft),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        if (trips > 0) ...[
                          const SizedBox(height: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                            decoration: BoxDecoration(
                              color: AppColors.surfaceSubtle,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: AppColors.line),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.alt_route_rounded, size: 13, color: AppColors.inkSoft),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    globalLanguage.choice(
                                      'May $trips na trip requisition para sa byahe ng truck na ito.',
                                      'Has $trips trip requisition(s) assigned for hauling.',
                                    ),
                                    style: const TextStyle(fontSize: 11, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        // ─── Onboard Equipment Kit (Permanent Truck Tools) ───
                        Builder(builder: (context) {
                          final onboardTools = (truck['onboard_tools'] as List<dynamic>?) ?? [];
                          if (onboardTools.isNotEmpty) {
                            return Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: Container(
                                padding: const EdgeInsets.all(10),
                                decoration: BoxDecoration(
                                  color: AppColors.surfaceSubtle,
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(color: AppColors.line),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Row(
                                          children: [
                                            const Icon(Icons.handyman_outlined, size: 14, color: AppColors.amber),
                                            const SizedBox(width: 6),
                                            Text(
                                              globalLanguage.choice('Kit ng Sasakyan (Onboard)', 'Onboard Kit'),
                                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.ink),
                                            ),
                                          ],
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                          decoration: BoxDecoration(
                                            color: AppColors.greenTint,
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(color: AppColors.greenBorder),
                                          ),
                                          child: Text(
                                            '${onboardTools.length} ${globalLanguage.choice('Gamit', 'Tools')}',
                                            style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.greenOk),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      globalLanguage.choice(
                                        'Permanenteng gamit sa truck. Walang countdown at hindi nag-eexpire.',
                                        'Permanent truck tools. No loan countdown; never expires.',
                                      ),
                                      style: const TextStyle(fontSize: 10, color: AppColors.inkSoft),
                                    ),
                                    const SizedBox(height: 8),
                                    Wrap(
                                      spacing: 6,
                                      runSpacing: 6,
                                      children: onboardTools.map((tool) {
                                        final t = tool as Map<String, dynamic>;
                                        final tName = t['item_name'] ?? 'Tool';
                                        final tTag = t['asset_tag'] ?? '';
                                        return Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                          decoration: BoxDecoration(
                                            color: AppColors.surface,
                                            borderRadius: BorderRadius.circular(6),
                                            border: Border.all(color: AppColors.lineStrong),
                                          ),
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              const Icon(Icons.check_circle, size: 12, color: AppColors.greenOk),
                                              const SizedBox(width: 4),
                                              Text(
                                                tName,
                                                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.ink),
                                              ),
                                              if (tTag.isNotEmpty) ...[
                                                const SizedBox(width: 4),
                                                Text(
                                                  '($tTag)',
                                                  style: const TextStyle(fontSize: 9.5, color: AppColors.inkSoft, fontFamily: 'monospace'),
                                                ),
                                              ],
                                            ],
                                          ),
                                        );
                                      }).toList(),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          } else {
                            return Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                decoration: BoxDecoration(
                                  color: AppColors.surfaceSubtle,
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(color: AppColors.line),
                                ),
                                child: Row(
                                  children: [
                                    const Icon(Icons.info_outline, size: 13, color: AppColors.inkSoft),
                                    const SizedBox(width: 6),
                                    Expanded(
                                      child: Text(
                                        globalLanguage.choice(
                                          'Walang naka-assign na Kit ng Sasakyan (Maaaring i-assign sa Web).',
                                          'No Onboard Kit assigned (Can be assigned via Web).',
                                        ),
                                        style: const TextStyle(fontSize: 10.5, color: AppColors.inkSoft),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          }
                        }),
                        const SizedBox(height: 12),
                        SizedBox(
                          width: double.infinity,
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor: AppColors.ink,
                              side: const BorderSide(color: AppColors.lineStrong),
                              padding: const EdgeInsets.symmetric(vertical: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                            ),
                            icon: const Icon(Icons.swap_horiz_rounded, size: 16, color: AppColors.amber),
                            label: Text(
                              globalLanguage.choice('Baguhin ang Katayuan', 'Change Status'),
                              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold),
                            ),
                            onPressed: () => _showStatusDialog(truck),
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Main Navigation Frame (Dynamic Role-Based Architecture)
// ---------------------------------------------------------
// ---------------------------------------------------------
// Sleek Custom Duarte Bottom Navigation Bar (No bulky pills, crisp single-line labels)
// ---------------------------------------------------------
class DuarteNavItem {
  final IconData icon;
  final IconData selectedIcon;
  final String label;
  final int badgeCount;

  const DuarteNavItem({
    required this.icon,
    required this.selectedIcon,
    required this.label,
    this.badgeCount = 0,
  });
}

class DuarteBottomBar extends StatelessWidget {
  final int selectedIndex;
  final ValueChanged<int> onItemSelected;
  final List<DuarteNavItem> items;

  const DuarteBottomBar({
    super.key,
    required this.selectedIndex,
    required this.onItemSelected,
    required this.items,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line, width: 1)),
        boxShadow: [
          BoxShadow(color: Color(0x0A000000), blurRadius: 8, offset: Offset(0, -2)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 60,
          child: Row(
            children: List.generate(items.length, (index) {
              final item = items[index];
              final isSelected = index == selectedIndex;
              return Expanded(
                child: InkWell(
                  onTap: () => onItemSelected(index),
                  splashColor: Colors.transparent,
                  highlightColor: Colors.transparent,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AnimatedContainer(
                          duration: const Duration(milliseconds: 180),
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 3.5),
                          decoration: BoxDecoration(
                            color: isSelected ? AppColors.amberTint : Colors.transparent,
                            borderRadius: BorderRadius.circular(14),
                          ),
                          child: Badge(
                            isLabelVisible: item.badgeCount > 0,
                            label: Text('${item.badgeCount}'),
                            backgroundColor: AppColors.amber,
                            child: Icon(
                              isSelected ? item.selectedIcon : item.icon,
                              size: 21,
                              color: isSelected ? AppColors.amber : AppColors.inkSoft,
                            ),
                          ),
                        ),
                        const SizedBox(height: 2.5),
                        Text(
                          item.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 10.5,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                            color: isSelected ? AppColors.amber : AppColors.inkSoft,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Tab 0: Supervisor Dashboard Screen (100% Web Parity with requisition/dashboard.php)
// ---------------------------------------------------------
class SupervisorDashboardScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final VoidCallback onNavigateToApprovals;
  final VoidCallback onNavigateToFleet;
  final VoidCallback onNavigateToRequests;

  const SupervisorDashboardScreen({
    super.key,
    required this.user,
    required this.onNavigateToApprovals,
    required this.onNavigateToFleet,
    required this.onNavigateToRequests,
  });

  @override
  State<SupervisorDashboardScreen> createState() => _SupervisorDashboardScreenState();
}

class _SupervisorDashboardScreenState extends State<SupervisorDashboardScreen> {
  Map<String, dynamic>? _dashboardData;
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _fetchDashboardData();
  }

  Future<void> _fetchDashboardData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/dashboard.php?user_id=${widget.user['id']}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final body = jsonDecode(res.body);
        if (body['success'] == true && body['data'] != null) {
          if (mounted) {
            setState(() {
              _dashboardData = body['data'] is Map<String, dynamic> ? body['data'] : null;
              _isLoading = false;
            });
            return;
          }
        }
      }
      if (mounted) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog ? 'Hindi ma-load ang dashboard.' : 'Failed to load dashboard.';
          _isLoading = false;
        });
      }
    } catch (e) {
      debugPrint('[SupervisorDashboard._fetchDashboardData] error: $e');
      if (mounted) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog ? 'Walang koneksyon sa server.' : 'Server connection unavailable.';
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _decide(int reqId, String decision) async {
    String? note;
    if (decision == 'declined') {
      final noteCtrl = TextEditingController();
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(
            globalLanguage.isTagalog ? 'Dahilan ng Pagtanggi' : 'Reason for Rejection',
            style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
          ),
          content: TextField(
            controller: noteCtrl,
            maxLines: 3,
            decoration: InputDecoration(
              hintText: globalLanguage.isTagalog
                  ? 'Ipasok ang dahilan kung bakit tinatanggihan ang kahilingan...'
                  : 'Enter reason for rejecting requisition...',
              border: const OutlineInputBorder(),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.redDanger, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.t('reject')),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
      note = noteCtrl.text.trim();
    } else {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.surface,
          title: Text(
            globalLanguage.isTagalog ? 'Aprubahan ang Requisition?' : 'Approve Requisition?',
            style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold, fontSize: 16),
          ),
          content: Text(
            globalLanguage.isTagalog
                ? 'Kumpirmahin na inaprubahan mo ang kahilingang ito para sa fleet operation.'
                : 'Confirm approval for this requisition in fleet operation.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: AppColors.greenOk, foregroundColor: Colors.white),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(globalLanguage.t('approve')),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
    }

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'decide',
          'requisition_id': reqId,
          'decision': decision,
          'decision_note': note ?? '',
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              globalLanguage.isTagalog
                  ? 'Requisition #$reqId matagumpay na na-${decision == "approved" ? "aprubahan" : "tanggihan"}.'
                  : 'Requisition #$reqId successfully ${decision == "approved" ? "approved" : "rejected"}.',
            ),
            backgroundColor: decision == 'approved' ? AppColors.greenOk : AppColors.redDanger,
          ),
        );
        _fetchDashboardData();
      }
    } catch (_) {}
  }

  void _showActivityProfileSheet(Map<String, dynamic> profile) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 38,
                height: 4,
                decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.amberTint,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.analytics_outlined, color: AppColors.amber, size: 24),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    globalLanguage.choice('Aking Activity Profile', 'My Activity Profile'),
                    style: const TextStyle(fontSize: 16.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                  ),
                ),
              ],
            ),
            const Divider(height: 24, color: AppColors.line),
            _buildProfileRow(
              Icons.receipt_long_outlined,
              globalLanguage.choice('Personal na Requisitions', 'Personal Requisitions'),
              '${profile['total_requisitions'] ?? 0}',
              AppColors.amber,
            ),
            const SizedBox(height: 8),
            _buildProfileRow(
              Icons.handyman_outlined,
              globalLanguage.choice('Aktibong Hiram na Gamit', 'Active Tools Held'),
              '${profile['active_loans'] ?? 0}',
              AppColors.charcoal,
            ),
            const SizedBox(height: 8),
            _buildProfileRow(
              Icons.shopping_bag_outlined,
              globalLanguage.choice('Personal na Special PO', 'Personal Special PO'),
              '${profile['pending_po_requests'] ?? 0}',
              AppColors.charcoal,
            ),
            const SizedBox(height: 20),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(globalLanguage.t('close')),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProfileRow(IconData icon, String title, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.surfaceSubtle,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.line),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(title, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: AppColors.ink)),
          ),
          Text(value, style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: color)),
        ],
      ),
    );
  }

  String _getGreeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) {
      return globalLanguage.choice('Magandang umaga', 'Good morning');
    } else if (hour < 18) {
      return globalLanguage.choice('Magandang hapon', 'Good afternoon');
    } else {
      return globalLanguage.choice('Magandang gabi', 'Good evening');
    }
  }

  @override
  Widget build(BuildContext context) {
    final metrics = (_dashboardData?['supervisor_metrics'] as Map<String, dynamic>?) ?? {};
    final priorityPending = (_dashboardData?['priority_pending'] as List<dynamic>?) ?? [];
    final activityProfile = (_dashboardData?['activity_profile'] as Map<String, dynamic>?) ?? {};

    final pendingCount = metrics['pending_count'] ?? 0;
    final approved7d = metrics['approved_7d'] ?? 0;
    final declined7d = metrics['declined_7d'] ?? 0;
    final approvalRate = metrics['approval_rate'];
    final turnaround = metrics['turnaround_label'] ?? '—';
    final urgentCount = metrics['urgent_count'] ?? 0;

    final fullName = widget.user['full_name'] ?? widget.user['username'] ?? 'Supervisor';

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.choice('Dashboard', 'Dashboard'),
        user: widget.user,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            tooltip: globalLanguage.t('refresh'),
            onPressed: _fetchDashboardData,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _fetchDashboardData,
        color: AppColors.amber,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
          children: [
            if (_errorMessage != null) ...[
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: AppColors.redTint,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppColors.redBorder),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.info_outline, color: AppColors.redDanger, size: 18),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _errorMessage!,
                        style: const TextStyle(fontSize: 12, color: AppColors.redDanger, fontWeight: FontWeight.w500),
                      ),
                    ),
                    TextButton(
                      onPressed: _fetchDashboardData,
                      child: Text(globalLanguage.t('refresh'), style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.redDanger)),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
            ],

            // 1. Welcome Hero Banner
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.line),
                boxShadow: const [
                  BoxShadow(color: Color(0x08000000), blurRadius: 8, offset: Offset(0, 2)),
                ],
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: AppColors.charcoal,
                    child: Text(
                      fullName.isNotEmpty ? fullName[0].toUpperCase() : 'S',
                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AppColors.amberOnDark),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _getGreeting(),
                          style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          fullName,
                          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        const SizedBox(height: 4),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.surfaceSubtle,
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: AppColors.line),
                          ),
                          child: const Text(
                            'SUPERVISOR — FLEET OPERATIONS',
                            style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.amber),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 12),

            // Quick Action Buttons
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.amber,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    icon: const Icon(Icons.fact_check_outlined, size: 17),
                    label: Text(
                      globalLanguage.choice('Pending Approvals ($pendingCount)', 'Pending Approvals ($pendingCount)'),
                      style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold),
                    ),
                    onPressed: widget.onNavigateToApprovals,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.ink,
                      side: const BorderSide(color: AppColors.lineStrong),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    icon: const Icon(Icons.local_shipping_outlined, size: 17, color: AppColors.inkSoft),
                    label: Text(
                      globalLanguage.choice('Fleet Trucks', 'Fleet Trucks'),
                      style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold),
                    ),
                    onPressed: widget.onNavigateToFleet,
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),

            // 2. Urgent / High-Priority Alert Banner
            if (urgentCount > 0) ...[
              InkWell(
                onTap: widget.onNavigateToApprovals,
                borderRadius: BorderRadius.circular(8),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                  decoration: BoxDecoration(
                    color: AppColors.redTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.redBorder),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.warning_amber_rounded, color: AppColors.redDanger, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.choice(
                            'May $urgentCount na kailangan ng agarang aksyon (Mataas na Prayoridad) →',
                            'High-Priority Action Required ($urgentCount over critical threshold) →',
                          ),
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.redDanger),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
            ] else if (pendingCount > 0) ...[
              InkWell(
                onTap: widget.onNavigateToApprovals,
                borderRadius: BorderRadius.circular(8),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: AppColors.amberTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.amberBorder),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.access_time_rounded, color: AppColors.amber, size: 18),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.choice(
                            'May $pendingCount na kahilingang naghihintay ng desisyon mo →',
                            '$pendingCount requisition(s) need your review →',
                          ),
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.amber),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
            ],

            // 3. Operational 4-Stat Metric Grid
            Row(
              children: [
                Expanded(
                  child: _buildSupStatCard(
                    icon: Icons.hourglass_top_rounded,
                    label: globalLanguage.choice('Naghihintay', 'Awaiting'),
                    value: '$pendingCount',
                    color: pendingCount > 0 ? AppColors.amber : AppColors.inkSoft,
                    border: pendingCount > 0 ? AppColors.amberBorder : AppColors.line,
                    onTap: widget.onNavigateToApprovals,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _buildSupStatCard(
                    icon: Icons.check_circle_outline,
                    label: globalLanguage.choice('Aprubado (7A)', 'Approved (7D)'),
                    value: '$approved7d',
                    color: AppColors.greenOk,
                    border: AppColors.greenBorder,
                    onTap: widget.onNavigateToApprovals,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _buildSupStatCard(
                    icon: Icons.highlight_off,
                    label: globalLanguage.choice('Tinanggihan', 'Declined (7D)'),
                    value: '$declined7d',
                    color: declined7d > 0 ? AppColors.redDanger : AppColors.inkSoft,
                    border: declined7d > 0 ? AppColors.redBorder : AppColors.line,
                    onTap: widget.onNavigateToApprovals,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _buildSupStatCard(
                    icon: Icons.speed_rounded,
                    label: globalLanguage.choice('Approval %', 'Approval Rate'),
                    value: approvalRate != null ? '$approvalRate%' : '—',
                    subText: turnaround != '—' ? 'avg $turnaround' : null,
                    color: AppColors.ink,
                    border: AppColors.line,
                    onTap: null,
                  ),
                ),
              ],
            ),

            const SizedBox(height: 14),

            // 4. "Waiting on you" Priority Approvals Queue
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          globalLanguage.choice('Naghihintay ng Desisyon', 'Waiting on you'),
                          style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        if (pendingCount > 0)
                          TextButton(
                            onPressed: widget.onNavigateToApprovals,
                            child: Text(
                              globalLanguage.choice('Buong pila ($pendingCount) →', 'Full queue ($pendingCount) →'),
                              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.amber),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    if (_isLoading) ...[
                      const Center(
                        child: Padding(
                          padding: EdgeInsets.symmetric(vertical: 24),
                          child: CircularProgressIndicator(color: AppColors.amber, strokeWidth: 2.5),
                        ),
                      ),
                    ] else if (priorityPending.isEmpty) ...[
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 10),
                        child: Column(
                          children: [
                            const Icon(Icons.check_circle_outline, size: 36, color: AppColors.greenOk),
                            const SizedBox(height: 8),
                            Text(
                              globalLanguage.choice('Walang nakabinbing kahilingan ngayon.', 'Approval queue clear — nothing pending review.'),
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                            ),
                          ],
                        ),
                      ),
                    ] else ...[
                      ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: priorityPending.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (ctx, idx) {
                          final r = priorityPending[idx];
                          final id = r['id'];
                          final reqName = r['requester_name'] ?? 'Personnel';
                          final reqPos = r['requester_position'] ?? '';
                          final itemCount = r['item_count'] ?? 1;
                          final score = double.tryParse('${r['priority_score']}') ?? 0.0;
                          final isUrgent = !empty(r['manual_urgent']) || score >= 70.0;
                          final plate = r['truck_plate_snapshot'] ?? r['plate_number'] ?? '';

                          return Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: AppColors.surfaceSubtle,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: isUrgent ? AppColors.amberBorder : AppColors.line),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Row(
                                      children: [
                                        Text('#$id', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, fontFamily: 'monospace', color: AppColors.ink)),
                                        const SizedBox(width: 8),
                                        Text('$reqName${reqPos.isNotEmpty ? " • $reqPos" : ""}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.ink)),
                                      ],
                                    ),
                                    if (isUrgent)
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(color: AppColors.redTint, borderRadius: BorderRadius.circular(4)),
                                        child: const Text('URGENT', style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.bold, color: AppColors.redDanger)),
                                      ),
                                  ],
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '${r['purpose'] ?? 'General logistics requisition'}${plate.isNotEmpty ? " • Fleet: $plate" : ""} • $itemCount item(s)',
                                  style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                                ),
                                const SizedBox(height: 10),
                                Row(
                                  children: [
                                    Expanded(
                                      child: ElevatedButton.icon(
                                        style: ElevatedButton.styleFrom(
                                          backgroundColor: AppColors.greenOk,
                                          foregroundColor: Colors.white,
                                          padding: const EdgeInsets.symmetric(vertical: 8),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                                        ),
                                        icon: const Icon(Icons.check, size: 15),
                                        label: Text(globalLanguage.t('approve'), style: const TextStyle(fontSize: 11.5)),
                                        onPressed: () => _decide(id, 'approved'),
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: OutlinedButton.icon(
                                        style: OutlinedButton.styleFrom(
                                          foregroundColor: AppColors.redDanger,
                                          side: const BorderSide(color: AppColors.redBorder),
                                          padding: const EdgeInsets.symmetric(vertical: 8),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                                        ),
                                        icon: const Icon(Icons.close, size: 15),
                                        label: Text(globalLanguage.t('reject'), style: const TextStyle(fontSize: 11.5)),
                                        onPressed: () => _decide(id, 'declined'),
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ],
                  ],
                ),
              ),
            ),

            const SizedBox(height: 14),

            // 5. Clean "My Activity" Hub
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.folder_shared_outlined, color: AppColors.amber, size: 20),
                        const SizedBox(width: 8),
                        Text(
                          globalLanguage.choice('Aking mga Gawain', 'My Activity'),
                          style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    const Divider(height: 1, color: AppColors.line),
                    const SizedBox(height: 6),

                    _buildSupTile(
                      icon: Icons.insights_rounded,
                      title: globalLanguage.choice('My Activity Profile', 'My Activity Profile'),
                      onTap: () => _showActivityProfileSheet(activityProfile),
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildSupTile(
                      icon: Icons.local_shipping_outlined,
                      title: globalLanguage.choice('Fleet Trucks (Mga Sasakyan)', 'Fleet Trucks Management'),
                      onTap: widget.onNavigateToFleet,
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildSupTile(
                      icon: Icons.assignment_outlined,
                      title: globalLanguage.choice('Lahat ng Kahilingan', 'All Requisitions'),
                      onTap: widget.onNavigateToRequests,
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildSupTile(
                      icon: Icons.inventory_2_outlined,
                      title: globalLanguage.choice('Katalogo ng Gamit', 'Browse Catalog'),
                      onTap: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => CatalogScreen(user: widget.user),
                          ),
                        );
                      },
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  bool empty(dynamic val) {
    if (val == null) return true;
    if (val is bool) return !val;
    if (val is num) return val == 0;
    if (val is String) return val.trim().isEmpty || val == '0';
    return false;
  }

  Widget _buildSupStatCard({
    required IconData icon,
    required String label,
    required String value,
    String? subText,
    required Color color,
    required Color border,
    VoidCallback? onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: border),
          boxShadow: const [
            BoxShadow(color: Color(0x06000000), blurRadius: 4, offset: Offset(0, 1)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: color),
            const SizedBox(height: 6),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 10, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: color),
            ),
            if (subText != null) ...[
              const SizedBox(height: 1),
              Text(
                subText,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: AppColors.inkSoft),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildSupTile({
    required IconData icon,
    required String title,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(7),
              decoration: BoxDecoration(
                color: AppColors.surfaceSubtle,
                borderRadius: BorderRadius.circular(7),
                border: Border.all(color: AppColors.line),
              ),
              child: Icon(icon, size: 19, color: AppColors.amber),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppColors.ink),
              ),
            ),
            const Icon(Icons.chevron_right, size: 18, color: AppColors.inkLight),
          ],
        ),
      ),
    );
  }
}

class MainNavigationScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const MainNavigationScreen({super.key, required this.user});

  @override
  State<MainNavigationScreen> createState() => _MainNavigationScreenState();
}

class _MainNavigationScreenState extends State<MainNavigationScreen> {
  int _currentIndex = 0;
  int _offlineQueueCount = 0;
  String? _myRequisitionsInitialTab;
  int? _myRequisitionsInitialSubSection;
  Timer? _notificationTimer;

  @override
  void initState() {
    super.initState();
    _checkOfflineQueue();
    _pollNotifications();
    _notificationTimer = Timer.periodic(const Duration(seconds: 30), (_) => _pollNotifications());
  }

  @override
  void dispose() {
    _notificationTimer?.cancel();
    super.dispose();
  }

  Future<void> _pollNotifications() async {
    final baseUrl = await AppConfig.getBaseUrl();
    if (!mounted) return;
    await globalNotifications.poll(baseUrl, widget.user);
  }

  Future<void> _checkOfflineQueue() async {
    final queue = await AppConfig.getOfflineQueue();
    if (mounted) setState(() => _offlineQueueCount = queue.length);
  }

  void _onTabTapped(int index) {
    setState(() => _currentIndex = index);
    _checkOfflineQueue();
  }

  List<Widget> _buildPages(String role) {
    if (role == 'inventory_staff') {
      return [
        InventoryVerifyReleaseScreen(user: widget.user),
        InventoryLoansScreen(user: widget.user),
        ItemStockCheckScreen(user: widget.user),
        InventoryStockScreen(user: widget.user),
        ProfileScreen(user: widget.user, onQueueChanged: _checkOfflineQueue),
      ];
    } else if (role == 'field_supervisor') {
      return [
        SupervisorDashboardScreen(
          user: widget.user,
          onNavigateToApprovals: () => setState(() => _currentIndex = 1),
          onNavigateToFleet: () => setState(() => _currentIndex = 2),
          onNavigateToRequests: () => setState(() => _currentIndex = 3),
        ),
        SupervisorApprovalsScreen(user: widget.user),
        FleetTrucksScreen(user: widget.user),
        MyRequisitionsScreen(user: widget.user, initialTab: 'All'),
        ProfileScreen(user: widget.user, onQueueChanged: _checkOfflineQueue),
      ];
    } else if (role == 'admin') {
      return [
        SupervisorApprovalsScreen(user: widget.user),
        InventoryVerifyReleaseScreen(user: widget.user),
        InventoryLoansScreen(user: widget.user),
        CatalogScreen(
          user: widget.user,
          onNavigateToRequests: () => setState(() => _currentIndex = 1),
        ),
        ProfileScreen(user: widget.user, onQueueChanged: _checkOfflineQueue),
      ];
    } else {
      return [
        RequesterDashboardScreen(
          user: widget.user,
          onNavigateToCatalog: () => setState(() => _currentIndex = 1),
          onNavigateToRequests: ({String? filterTab, int? subSection}) {
            setState(() {
              if (filterTab != null) _myRequisitionsInitialTab = filterTab;
              if (subSection != null) _myRequisitionsInitialSubSection = subSection;
              _currentIndex = 2;
            });
          },
          onNavigateToSpecialPo: () => setState(() => _currentIndex = 3),
        ),
        CatalogScreen(
          user: widget.user,
          onNavigateToRequests: () => setState(() => _currentIndex = 2),
          onNavigateToSpecialPo: ({String? defaultItem}) {
            setState(() => _currentIndex = 3);
            if (defaultItem != null) {
              WidgetsBinding.instance.addPostFrameCallback((_) {
                showNewItemRequestSheet(context, widget.user, initialItemName: defaultItem);
              });
            }
          },
        ),
        MyRequisitionsScreen(
          user: widget.user,
          initialTab: _myRequisitionsInitialTab,
          initialSubSection: _myRequisitionsInitialSubSection,
        ),
        SpecialPoScreen(user: widget.user),
        ProfileScreen(user: widget.user, onQueueChanged: _checkOfflineQueue),
      ];
    }
  }

  List<DuarteNavItem> _buildDestinations(String role) {
    if (role == 'inventory_staff') {
      return [
        DuarteNavItem(
          icon: Icons.qr_code_scanner_outlined,
          selectedIcon: Icons.qr_code_scanner,
          label: globalLanguage.t('nav_release'),
        ),
        DuarteNavItem(
          icon: Icons.handyman_outlined,
          selectedIcon: Icons.handyman,
          label: globalLanguage.t('nav_loans'),
        ),
        DuarteNavItem(
          icon: Icons.qr_code_2_outlined,
          selectedIcon: Icons.qr_code_2,
          label: globalLanguage.t('nav_scan_item'),
        ),
        DuarteNavItem(
          icon: Icons.warehouse_outlined,
          selectedIcon: Icons.warehouse,
          label: globalLanguage.t('nav_stock'),
        ),
        DuarteNavItem(
          icon: Icons.person_outline,
          selectedIcon: Icons.person,
          label: globalLanguage.t('nav_profile'),
          badgeCount: _offlineQueueCount,
        ),
      ];
    } else if (role == 'field_supervisor') {
      return [
        DuarteNavItem(
          icon: Icons.dashboard_outlined,
          selectedIcon: Icons.dashboard_rounded,
          label: globalLanguage.choice('Dashboard', 'Dashboard'),
        ),
        DuarteNavItem(
          icon: Icons.fact_check_outlined,
          selectedIcon: Icons.fact_check,
          label: globalLanguage.t('nav_approvals'),
        ),
        DuarteNavItem(
          icon: Icons.local_shipping_outlined,
          selectedIcon: Icons.local_shipping,
          label: globalLanguage.choice('Fleet Trucks', 'Fleet Trucks'),
        ),
        DuarteNavItem(
          icon: Icons.assignment_outlined,
          selectedIcon: Icons.assignment,
          label: globalLanguage.t('nav_requests'),
        ),
        DuarteNavItem(
          icon: Icons.person_outline,
          selectedIcon: Icons.person,
          label: globalLanguage.t('nav_profile'),
          badgeCount: _offlineQueueCount,
        ),
      ];
    } else if (role == 'admin') {
      return [
        DuarteNavItem(
          icon: Icons.fact_check_outlined,
          selectedIcon: Icons.fact_check,
          label: globalLanguage.t('nav_approvals'),
        ),
        DuarteNavItem(
          icon: Icons.qr_code_scanner_outlined,
          selectedIcon: Icons.qr_code_scanner,
          label: globalLanguage.t('nav_release'),
        ),
        DuarteNavItem(
          icon: Icons.handyman_outlined,
          selectedIcon: Icons.handyman,
          label: globalLanguage.t('nav_loans'),
        ),
        DuarteNavItem(
          icon: Icons.inventory_2_outlined,
          selectedIcon: Icons.inventory_2,
          label: globalLanguage.t('nav_catalog'),
        ),
        DuarteNavItem(
          icon: Icons.person_outline,
          selectedIcon: Icons.person,
          label: globalLanguage.t('nav_profile'),
          badgeCount: _offlineQueueCount,
        ),
      ];
    } else {
      return [
        DuarteNavItem(
          icon: Icons.dashboard_outlined,
          selectedIcon: Icons.dashboard_rounded,
          label: globalLanguage.choice('Dashboard', 'Dashboard'),
        ),
        DuarteNavItem(
          icon: Icons.inventory_2_outlined,
          selectedIcon: Icons.inventory_2,
          label: globalLanguage.t('nav_catalog'),
        ),
        DuarteNavItem(
          icon: Icons.assignment_outlined,
          selectedIcon: Icons.assignment,
          label: globalLanguage.t('nav_requests'),
        ),
        DuarteNavItem(
          icon: Icons.shopping_bag_outlined,
          selectedIcon: Icons.shopping_bag,
          label: globalLanguage.t('nav_special_po'),
        ),
        DuarteNavItem(
          icon: Icons.person_outline,
          selectedIcon: Icons.person,
          label: globalLanguage.t('nav_profile'),
          badgeCount: _offlineQueueCount,
        ),
      ];
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: globalLanguage,
      builder: (context, _) {
        final role = (widget.user['role'] ?? 'driver_helper').toString().toLowerCase();
        final pages = _buildPages(role);
        final destinations = _buildDestinations(role);
        final safeIndex = _currentIndex >= pages.length ? 0 : _currentIndex;

        return Scaffold(
          body: IndexedStack(
            index: safeIndex,
            children: pages,
          ),
          bottomNavigationBar: DuarteBottomBar(
            selectedIndex: safeIndex,
            onItemSelected: _onTabTapped,
            items: destinations,
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------
// Tab 0: Requester Dashboard & "My Activity" Hub (100% Web Parity with requisition/home.php)
// ---------------------------------------------------------
class RequesterDashboardScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final VoidCallback onNavigateToCatalog;
  final void Function({String? filterTab, int? subSection}) onNavigateToRequests;
  final VoidCallback onNavigateToSpecialPo;

  const RequesterDashboardScreen({
    super.key,
    required this.user,
    required this.onNavigateToCatalog,
    required this.onNavigateToRequests,
    required this.onNavigateToSpecialPo,
  });

  @override
  State<RequesterDashboardScreen> createState() => _RequesterDashboardScreenState();
}

class _RequesterDashboardScreenState extends State<RequesterDashboardScreen> {
  Map<String, dynamic>? _dashboardData;
  bool _isLoading = true;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _fetchDashboardData();
  }

  Future<void> _fetchDashboardData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/dashboard.php?user_id=${widget.user['id']}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final body = jsonDecode(res.body);
        if (body['success'] == true && body['data'] != null) {
          if (mounted) {
            setState(() {
              _dashboardData = body['data'] is Map<String, dynamic> ? body['data'] : null;
              _isLoading = false;
            });
            return;
          }
        }
      }
      if (mounted) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog ? 'Hindi ma-load ang dashboard.' : 'Failed to load dashboard.';
          _isLoading = false;
        });
      }
    } catch (e) {
      debugPrint('[RequesterDashboard._fetchDashboardData] error: $e');
      if (mounted) {
        setState(() {
          _errorMessage = globalLanguage.isTagalog ? 'Walang koneksyon sa server.' : 'Server connection unavailable.';
          _isLoading = false;
        });
      }
    }
  }

  void _showActivityProfileSheet(Map<String, dynamic> profile) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 38,
                height: 4,
                decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.amberTint,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.analytics_outlined, color: AppColors.amber, size: 24),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        globalLanguage.choice('Aking Activity Profile', 'My Activity Profile'),
                        style: const TextStyle(fontSize: 16.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 24, color: AppColors.line),
            _buildProfileStatRow(
              Icons.receipt_long_outlined,
              globalLanguage.choice('Kabuuang Requisitions', 'Total Requisitions'),
              '${profile['total_requisitions'] ?? 0}',
              AppColors.amber,
            ),
            const SizedBox(height: 8),
            _buildProfileStatRow(
              Icons.check_circle_outline,
              globalLanguage.choice('Nai-release sa Bodega', 'Released Requisitions'),
              '${profile['released_requisitions'] ?? 0}',
              AppColors.greenOk,
            ),
            const SizedBox(height: 8),
            _buildProfileStatRow(
              Icons.hourglass_top_rounded,
              globalLanguage.choice('Kasalukuyang Aktibo', 'Active Requisitions'),
              '${profile['active_requests'] ?? 0}',
              AppColors.blueInfo,
            ),
            const SizedBox(height: 8),
            _buildProfileStatRow(
              Icons.construction_outlined,
              globalLanguage.choice('Kabuuang Hiram na Gamit', 'Total Tool Loans'),
              '${profile['total_loans'] ?? 0}',
              AppColors.charcoal,
            ),
            const SizedBox(height: 8),
            _buildProfileStatRow(
              Icons.handyman_outlined,
              globalLanguage.choice('Aktibong Gamit na Hawak', 'Active Tools Held'),
              '${profile['active_loans'] ?? 0}',
              (profile['active_loans'] ?? 0) > 0 ? AppColors.amber : AppColors.inkSoft,
            ),
            if ((profile['overdue_loans'] ?? 0) > 0) ...[
              const SizedBox(height: 8),
              _buildProfileStatRow(
                Icons.warning_amber_rounded,
                globalLanguage.choice('Lampas sa Takdang Araw', 'Overdue Loans'),
                '${profile['overdue_loans']}',
                AppColors.redDanger,
              ),
            ],
            const SizedBox(height: 8),
            _buildProfileStatRow(
              Icons.shopping_bag_outlined,
              globalLanguage.choice('Pending Special PO Requests', 'Pending PO Requests'),
              '${profile['pending_po_requests'] ?? 0}',
              AppColors.charcoal,
            ),
            const SizedBox(height: 20),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(globalLanguage.t('close')),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProfileStatRow(IconData icon, String title, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.surfaceSubtle,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.line),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(title, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: AppColors.ink)),
          ),
          Text(value, style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: color)),
        ],
      ),
    );
  }

  String _getGreeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) {
      return globalLanguage.choice('Magandang umaga', 'Good morning');
    } else if (hour < 18) {
      return globalLanguage.choice('Magandang hapon', 'Good afternoon');
    } else {
      return globalLanguage.choice('Magandang gabi', 'Good evening');
    }
  }

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'released':
        return AppColors.greenOk;
      case 'pending':
        return AppColors.amber;
      case 'rejected':
      case 'declined':
      case 'cancelled':
        return AppColors.redDanger;
      default:
        return AppColors.charcoal;
    }
  }

  Color _getStatusBg(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'released':
        return AppColors.greenTint;
      case 'pending':
        return AppColors.amberTint;
      case 'rejected':
      case 'declined':
      case 'cancelled':
        return AppColors.redTint;
      default:
        return AppColors.surfaceSubtle;
    }
  }

  Color _getStatusBorder(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'released':
        return AppColors.greenBorder;
      case 'pending':
        return AppColors.amberBorder;
      case 'rejected':
      case 'declined':
      case 'cancelled':
        return AppColors.redBorder;
      default:
        return AppColors.line;
    }
  }

  @override
  Widget build(BuildContext context) {
    final metrics = (_dashboardData?['metrics'] as Map<String, dynamic>?) ?? {};
    final recentRequests = (_dashboardData?['recent_requests'] as List<dynamic>?) ?? [];
    final activityProfile = (_dashboardData?['activity_profile'] as Map<String, dynamic>?) ?? {};

    final activeRequests = metrics['active_requests'] ?? 0;
    final toolsBorrowed = metrics['tools_borrowed'] ?? 0;
    final overdueTools = metrics['overdue_tools'] ?? 0;
    final readyForPickup = metrics['ready_for_pickup'] ?? 0;

    final fullName = widget.user['full_name'] ?? widget.user['username'] ?? 'User';
    final position = widget.user['position'] ?? 'Personnel';
    final roleDisplay = 'PERSONNEL — ${position.toString().toUpperCase()}';

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.choice('Dashboard', 'Dashboard'),
        user: widget.user,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            tooltip: globalLanguage.t('refresh'),
            onPressed: _fetchDashboardData,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _fetchDashboardData,
        color: AppColors.amber,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
          children: [
            if (_errorMessage != null) ...[
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: AppColors.redTint,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppColors.redBorder),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.info_outline, color: AppColors.redDanger, size: 18),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _errorMessage!,
                        style: const TextStyle(fontSize: 12, color: AppColors.redDanger, fontWeight: FontWeight.w500),
                      ),
                    ),
                    TextButton(
                      onPressed: _fetchDashboardData,
                      child: Text(globalLanguage.t('refresh'), style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.redDanger)),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
            ],
            // 1. Welcome Hero Banner (100% Web Parity with welcome-hero)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.line),
                boxShadow: const [
                  BoxShadow(color: Color(0x08000000), blurRadius: 8, offset: Offset(0, 2)),
                ],
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: AppColors.charcoal,
                    child: Text(
                      fullName.isNotEmpty ? fullName[0].toUpperCase() : 'U',
                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AppColors.amberOnDark),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _getGreeting(),
                          style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          fullName,
                          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        const SizedBox(height: 4),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.surfaceSubtle,
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: AppColors.line),
                          ),
                          child: Text(
                            roleDisplay,
                            style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.amber),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 12),

            // 2. Quick Action Buttons Row (Web Parity: Browse Catalog + My Requests)
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.amber,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    icon: const Icon(Icons.add_circle_outline, size: 17),
                    label: Text(
                      globalLanguage.choice('Tingnan ang Katalogo', 'Browse Catalog'),
                      style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold),
                    ),
                    onPressed: widget.onNavigateToCatalog,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.ink,
                      side: const BorderSide(color: AppColors.lineStrong),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    icon: const Icon(Icons.assignment_outlined, size: 17, color: AppColors.inkSoft),
                    label: Text(
                      globalLanguage.choice('Aking mga Kahilingan', 'My Requests'),
                      style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold),
                    ),
                    onPressed: () => widget.onNavigateToRequests(subSection: 0),
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),

            // 3. Status Alert Banners (Web Parity)
            if (readyForPickup > 0) ...[
              InkWell(
                onTap: () => widget.onNavigateToRequests(filterTab: 'Approved', subSection: 0),
                borderRadius: BorderRadius.circular(8),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                  decoration: BoxDecoration(
                    color: AppColors.greenTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.greenBorder),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle, color: AppColors.greenOk, size: 19),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          '$readyForPickup ${globalLanguage.choice("inaprubahan at handa nang kunin gamit ang QR", "approved and ready for QR pickup")}',
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.greenOk),
                        ),
                      ),
                      const Icon(Icons.chevron_right, color: AppColors.greenOk, size: 18),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],

            if (overdueTools > 0) ...[
              InkWell(
                onTap: () => widget.onNavigateToRequests(subSection: 1),
                borderRadius: BorderRadius.circular(8),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                  decoration: BoxDecoration(
                    color: AppColors.redTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.redBorder),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.warning_amber_rounded, color: AppColors.redDanger, size: 19),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'May $overdueTools kang hiram na gamit na lampas na sa takdang araw. ${globalLanguage.choice("Tingnan ang Hiram na Gamit →", "Review Borrowed Tools →")}',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.redDanger),
                        ),
                      ),
                      const Icon(Icons.chevron_right, color: AppColors.redDanger, size: 18),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],

            // 4. Metric Stat Grid (Web Parity: stat-grid-v2)
            Row(
              children: [
                Expanded(
                  child: _buildStatCard(
                    icon: Icons.assignment_outlined,
                    label: globalLanguage.choice('Aktibong kahilingan', 'Active requests'),
                    value: '$activeRequests',
                    isActive: activeRequests > 0,
                    onTap: () => widget.onNavigateToRequests(subSection: 0),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _buildStatCard(
                    icon: Icons.handyman_outlined,
                    label: globalLanguage.choice('Hiram na gamit', 'Tools borrowed'),
                    value: '$toolsBorrowed',
                    subText: overdueTools > 0 ? '$overdueTools overdue' : null,
                    isDanger: overdueTools > 0,
                    onTap: () => widget.onNavigateToRequests(subSection: 1),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _buildStatCard(
                    icon: Icons.qr_code_2_rounded,
                    label: globalLanguage.choice('Handang kunin', 'Ready for pickup'),
                    value: '$readyForPickup',
                    isWarn: readyForPickup > 0,
                    onTap: () => widget.onNavigateToRequests(filterTab: 'Approved', subSection: 0),
                  ),
                ),
              ],
            ),

            const SizedBox(height: 14),

            // 5. Dedicated "MY ACTIVITY" Hub Card (Exact feature requested by user!)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.folder_shared_outlined, color: AppColors.amber, size: 20),
                        const SizedBox(width: 8),
                        Text(
                          globalLanguage.choice('Aking mga Gawain', 'My Activity'),
                          style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    const Divider(height: 1, color: AppColors.line),
                    const SizedBox(height: 6),

                    _buildActivityTile(
                      icon: Icons.insights_rounded,
                      title: globalLanguage.choice('My Activity Profile', 'My Activity Profile'),
                      badgeCount: null,
                      onTap: () => _showActivityProfileSheet(activityProfile),
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildActivityTile(
                      icon: Icons.assignment_outlined,
                      title: globalLanguage.choice('My Requests', 'My Requests'),
                      badgeCount: activeRequests > 0 ? activeRequests : null,
                      onTap: () => widget.onNavigateToRequests(subSection: 0),
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildActivityTile(
                      icon: Icons.construction_outlined,
                      title: globalLanguage.choice('My Borrowed Tools', 'My Borrowed Tools'),
                      badgeCount: toolsBorrowed > 0 ? toolsBorrowed : null,
                      badgeColor: overdueTools > 0 ? AppColors.redDanger : AppColors.amber,
                      onTap: () => widget.onNavigateToRequests(subSection: 1),
                    ),
                    const Divider(height: 1, color: AppColors.line),

                    _buildActivityTile(
                      icon: Icons.shopping_bag_outlined,
                      title: globalLanguage.choice('My Purchase Requests (PO)', 'My Purchase Requests (PO)'),
                      badgeCount: (activityProfile['pending_po_requests'] ?? 0) > 0 ? activityProfile['pending_po_requests'] : null,
                      onTap: widget.onNavigateToSpecialPo,
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 14),

            // 6. "Your recent requests" Section (Web Parity)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          globalLanguage.choice('Huling mga Kahilingan', 'Your Recent Requests'),
                          style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        TextButton(
                          onPressed: () => widget.onNavigateToRequests(subSection: 0),
                          child: Text(
                            globalLanguage.choice('Tingnan lahat →', 'See all →'),
                            style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: AppColors.amber),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    if (_isLoading) ...[
                      const Center(
                        child: Padding(
                          padding: EdgeInsets.symmetric(vertical: 24),
                          child: CircularProgressIndicator(color: AppColors.amber, strokeWidth: 2.5),
                        ),
                      ),
                    ] else if (recentRequests.isEmpty) ...[
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 10),
                        child: Column(
                          children: [
                            const Icon(Icons.assignment_outlined, size: 36, color: AppColors.inkLight),
                            const SizedBox(height: 8),
                            Text(
                              globalLanguage.choice('Wala ka pang naisusumiteng request.', "You haven't submitted any requests yet."),
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                            ),
                            const SizedBox(height: 8),
                            TextButton(
                              onPressed: widget.onNavigateToCatalog,
                              child: Text(globalLanguage.choice('Mag-browse sa katalogo', 'Browse the catalog')),
                            ),
                          ],
                        ),
                      ),
                    ] else ...[
                      ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: recentRequests.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (ctx, idx) {
                          final r = recentRequests[idx];
                          final id = r['id'];
                          final itemCount = r['item_count'] ?? 0;
                          final status = (r['status'] ?? 'pending').toString().toUpperCase();
                          final createdAt = r['created_at'] ?? '';

                          return InkWell(
                            onTap: () => widget.onNavigateToRequests(subSection: 0),
                            borderRadius: BorderRadius.circular(8),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              decoration: BoxDecoration(
                                color: AppColors.surfaceSubtle,
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: AppColors.line),
                              ),
                              child: Row(
                                children: [
                                  Text(
                                    '#$id',
                                    style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          '$itemCount item(s)',
                                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink),
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          createdAt,
                                          style: const TextStyle(fontSize: 11, color: AppColors.inkSoft, fontFamily: 'monospace'),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                    decoration: BoxDecoration(
                                      color: _getStatusBg(status),
                                      borderRadius: BorderRadius.circular(5),
                                      border: Border.all(color: _getStatusBorder(status)),
                                    ),
                                    child: Text(
                                      status,
                                      style: TextStyle(
                                        fontSize: 10.5,
                                        fontWeight: FontWeight.bold,
                                        color: _getStatusColor(status),
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildStatCard({
    required IconData icon,
    required String label,
    required String value,
    String? subText,
    bool isActive = false,
    bool isDanger = false,
    bool isWarn = false,
    VoidCallback? onTap,
  }) {
    Color cardBorder = AppColors.line;
    Color iconColor = AppColors.inkSoft;
    Color valueColor = AppColors.ink;

    if (isDanger) {
      cardBorder = AppColors.redBorder;
      iconColor = AppColors.redDanger;
      valueColor = AppColors.redDanger;
    } else if (isWarn) {
      cardBorder = AppColors.amberBorder;
      iconColor = AppColors.amber;
      valueColor = AppColors.amber;
    } else if (isActive) {
      cardBorder = AppColors.greenBorder;
      iconColor = AppColors.greenOk;
    }

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: cardBorder),
          boxShadow: const [
            BoxShadow(color: Color(0x06000000), blurRadius: 4, offset: Offset(0, 1)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 20, color: iconColor),
            const SizedBox(height: 8),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 10.5, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
            ),
            const SizedBox(height: 3),
            Text(
              value,
              style: TextStyle(fontSize: 19, fontWeight: FontWeight.bold, color: valueColor),
            ),
            if (subText != null) ...[
              const SizedBox(height: 2),
              Text(
                subText,
                style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.redDanger),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildActivityTile({
    required IconData icon,
    required String title,
    int? badgeCount,
    Color? badgeColor,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(7),
              decoration: BoxDecoration(
                color: AppColors.surfaceSubtle,
                borderRadius: BorderRadius.circular(7),
                border: Border.all(color: AppColors.line),
              ),
              child: Icon(icon, size: 19, color: AppColors.amber),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppColors.ink),
              ),
            ),
            if (badgeCount != null) ...[
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: badgeColor ?? AppColors.amber,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  '$badgeCount',
                  style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                ),
              ),
              const SizedBox(width: 4),
            ],
            const Icon(Icons.chevron_right, size: 18, color: AppColors.inkLight),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------
// Tab 1: Catalog Screen with Multi-Item Cart
// ---------------------------------------------------------
class CatalogScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final VoidCallback? onNavigateToRequests;
  final void Function({String? defaultItem})? onNavigateToSpecialPo;
  const CatalogScreen({
    super.key,
    required this.user,
    this.onNavigateToRequests,
    this.onNavigateToSpecialPo,
  });

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  String _baseUrl = '';
  bool _isGridView = true;
  List<dynamic> _items = [];
  List<dynamic> _trucks = [];
  List<dynamic> _filteredItems = [];
  bool _isLoading = true;
  bool _isOffline = false;
  String _searchQuery = '';
  String _selectedCategory = 'All';
  List<String> _categories = ['All'];

  @override
  void initState() {
    super.initState();
    _fetchCatalog();
  }

  Future<void> _fetchCatalog() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      _baseUrl = baseUrl;
      final isOfficeStaff = (widget.user['position'] ?? '').toString().toLowerCase() == 'office_staff';
      final url = Uri.parse('$baseUrl/catalog.php?user_id=${widget.user['id']}&position=${widget.user['position'] ?? ''}');
      final res = await http.get(
        url,
        headers: AppConfig.authHeaders(widget.user, isJson: false),
      ).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        var items = (payload['items'] as List<dynamic>?) ?? [];
        final trucks = (payload['trucks'] as List<dynamic>?) ?? [];

        if (isOfficeStaff) {
          items = items.where((it) => (it['category_name'] ?? '') == 'Office Supplies').toList();
        }

        await AppConfig.cacheCatalog(items, trucks);

        final cats = <String>{'All'};
        for (var it in items) {
          if (it['category_name'] != null) cats.add(it['category_name'].toString());
        }

        if (mounted) {
          setState(() {
            _items = items;
            _trucks = trucks;
            _categories = cats.toList();
            _isOffline = false;
            _applyFilter();
          });
        }
        return;
      }
    } catch (_) {
      final cached = await AppConfig.getCachedCatalog();
      if (mounted && cached['items']!.isNotEmpty) {
        final items = cached['items']!;
        final trucks = cached['trucks']!;
        final cats = <String>{'All'};
        for (var it in items) {
          if (it['category_name'] != null) cats.add(it['category_name'].toString());
        }
        setState(() {
          _items = items;
          _trucks = trucks;
          _categories = cats.toList();
          _isOffline = true;
          _applyFilter();
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _applyFilter() {
    setState(() {
      _filteredItems = _items.where((it) {
        final matchesCat = (_selectedCategory == 'All') || (it['category_name'] == _selectedCategory);
        final matchesSearch = _searchQuery.isEmpty ||
            (it['name']?.toString().toLowerCase().contains(_searchQuery.toLowerCase()) ?? false) ||
            (it['item_code']?.toString().toLowerCase().contains(_searchQuery.toLowerCase()) ?? false);
        return matchesCat && matchesSearch;
      }).toList();
    });
  }

  void _openAddToCartSheet(Map<String, dynamic> item) {
    final rawStock = int.tryParse((item['stock'] ?? item['quantity_on_hand'] ?? 0).toString()) ?? 0;
    final isBorrowable = item['is_borrowable'] == true ||
        item['is_borrowable'] == 1 ||
        item['borrow_mode'] == 'borrow' ||
        item['borrow_mode'] == 'either';
    final variants = (item['variants'] as List<dynamic>?) ?? [];
    String? selectedVariant = variants.isNotEmpty ? variants.first['variant_value']?.toString() : null;
    int qty = 1;
    bool isBorrow = (item['borrow_mode'] == 'borrow');
    int days = 3;

    // Subscription & Out-of-Stock state (100% Web Parity)
    bool isSubscribed = false;
    bool isCheckingSub = false;
    bool isTogglingSub = false;
    bool hasCheckedSub = false;
    String? lastCheckedVariant;

    Future<void> checkSub(void Function(void Function()) setSheetState, String? variant) async {
      isCheckingSub = true;
      lastCheckedVariant = variant;
      setSheetState(() {});
      try {
        final baseUrl = await AppConfig.getBaseUrl();
        final vParam = (variant != null && variant.trim().isNotEmpty) ? '&variant=${Uri.encodeComponent(variant)}' : '';
        final url = Uri.parse('$baseUrl/subscribe.php?item_id=${item['id']}&user_id=${widget.user['id']}&token=${widget.user['token'] ?? ''}$vParam');
        final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 4));
        if (res.statusCode == 200) {
          final data = jsonDecode(res.body);
          if (data['success'] == true && data['data'] != null) {
            isSubscribed = data['data']['subscribed'] == true;
          }
        }
      } catch (_) {}
      isCheckingSub = false;
      setSheetState(() {});
    }

    Future<void> toggleSub(BuildContext sheetCtx, void Function(void Function()) setSheetState, String? variant) async {
      isTogglingSub = true;
      setSheetState(() {});
      try {
        final baseUrl = await AppConfig.getBaseUrl();
        final url = Uri.parse('$baseUrl/subscribe.php');
        final res = await http.post(
          url,
          headers: AppConfig.authHeaders(widget.user),
          body: jsonEncode({
            'item_id': item['id'],
            'user_id': widget.user['id'],
            'token': widget.user['token'] ?? '',
            'variant': variant,
            'action': 'toggle',
          }),
        ).timeout(const Duration(seconds: 5));
        if (res.statusCode == 200) {
          final data = jsonDecode(res.body);
          if (data['success'] == true && data['data'] != null) {
            final newSub = data['data']['subscribed'] == true;
            final msg = data['data']['message'] ?? (newSub ? 'You will be notified when this item is back in stock.' : 'Stock notification removed.');
            isSubscribed = newSub;
            isTogglingSub = false;
            setSheetState(() {});
            if (sheetCtx.mounted) {
              ScaffoldMessenger.of(sheetCtx).showSnackBar(
                SnackBar(
                  content: Text(msg),
                  backgroundColor: newSub ? AppColors.greenOk : AppColors.charcoal,
                  duration: const Duration(seconds: 3),
                ),
              );
            }
            return;
          }
        }
      } catch (_) {
        if (sheetCtx.mounted) {
          ScaffoldMessenger.of(sheetCtx).showSnackBar(
            SnackBar(
              content: Text(globalLanguage.isTagalog
                  ? 'Hindi ma-update ang subscription. Pakisubukan muli.'
                  : 'Unable to update subscription. Please try again.'),
              backgroundColor: AppColors.redDanger,
            ),
          );
        }
      }
      isTogglingSub = false;
      setSheetState(() {});
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          int currentStock = rawStock;
          String? currentVariantImg;

          if (selectedVariant != null && variants.isNotEmpty) {
            for (var v in variants) {
              if (v['variant_value']?.toString() == selectedVariant) {
                final vStock = int.tryParse(v['quantity_on_hand']?.toString() ?? '0') ?? 0;
                currentStock = vStock;
                currentVariantImg = AppConfig.resolveImageUrl(
                  v['image_url'],
                  filename: v['image_filename'],
                  activeBaseUrl: _baseUrl,
                );
                break;
              }
            }
          }

          final effectiveImg = currentVariantImg ??
              AppConfig.resolveImageUrl(
                item['image_url'],
                filename: item['image_filename'],
                activeBaseUrl: _baseUrl,
              );
          final isOutOfStock = currentStock <= 0;

          // Trigger stock subscription check if item or selected variant is out of stock
          if (isOutOfStock && (!hasCheckedSub || lastCheckedVariant != selectedVariant)) {
            hasCheckedSub = true;
            WidgetsBinding.instance.addPostFrameCallback((_) {
              checkSub(setSheetState, selectedVariant);
            });
          }

          return Container(
            decoration: const BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
            ),
            padding: EdgeInsets.fromLTRB(20, 14, 20, MediaQuery.of(ctx).viewInsets.bottom + 24),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Pull Handle
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: AppColors.lineStrong,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Shopee Product Header (Image + Title + Stock)
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        width: 88,
                        height: 88,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: AppColors.line),
                        ),
                        child: AppItemImage(
                          imageUrl: effectiveImg,
                          width: 88,
                          height: 88,
                          borderRadius: BorderRadius.circular(9),
                          fit: BoxFit.cover,
                        ),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              item['name'] ?? 'Item',
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                                color: AppColors.ink,
                                height: 1.25,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Code: ${item['item_code'] ?? 'N/A'} • ${item['category_name'] ?? 'General'}',
                              style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                            ),
                            if (item['brand'] != null && item['brand'].toString().trim().isNotEmpty) ...[
                              const SizedBox(height: 2),
                              Text(
                                'Brand: ${item['brand']}',
                                style: const TextStyle(fontSize: 11, color: AppColors.inkLight),
                              ),
                            ],
                            const SizedBox(height: 6),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                              decoration: BoxDecoration(
                                color: isOutOfStock ? AppColors.redTint : AppColors.greenTint,
                                borderRadius: BorderRadius.circular(4),
                                border: Border.all(color: isOutOfStock ? AppColors.redBorder : AppColors.greenBorder),
                              ),
                              child: Text(
                                isOutOfStock ? 'Out of Stock' : 'Stock: $currentStock ${item['unit'] ?? 'pcs'}',
                                style: TextStyle(
                                  color: isOutOfStock ? AppColors.redDanger : AppColors.greenOk,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 24, color: AppColors.line),

                  // Shopee-style Variant Selection Chips
                  if (variants.isNotEmpty) ...[
                    Text(
                      item['variant_label'] != null && item['variant_label'].toString().trim().isNotEmpty
                          ? 'Piliin ang ${item['variant_label']}:'
                          : 'Pumili ng Option / Variant:',
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: variants.map<Widget>((v) {
                        final vVal = v['variant_value']?.toString() ?? '';
                        final vStock = int.tryParse(v['quantity_on_hand']?.toString() ?? '0') ?? 0;
                        final isSelected = selectedVariant == vVal;
                        return ChoiceChip(
                          label: Text('$vVal ($vStock ${item['unit'] ?? 'pcs'})'),
                          selected: isSelected,
                          selectedColor: AppColors.surfaceSubtle,
                          backgroundColor: AppColors.paper,
                          side: BorderSide(color: isSelected ? AppColors.amber : AppColors.line),
                          labelStyle: TextStyle(
                            color: isSelected ? AppColors.amber : AppColors.ink,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                            fontSize: 12,
                          ),
                          onSelected: (sel) {
                            if (sel) {
                              setSheetState(() {
                                selectedVariant = vVal;
                                if (qty > vStock && vStock > 0) qty = vStock;
                              });
                            }
                          },
                        );
                      }).toList(),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // In-Stock Requisition Options (Borrow vs Consume)
                  if (!isOutOfStock && isBorrowable) ...[
                    const Text('Mode of Requisition', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink)),
                    const SizedBox(height: 6),
                    SegmentedButton<bool>(
                      style: ButtonStyle(
                        backgroundColor: WidgetStateProperty.resolveWith((states) {
                          if (states.contains(WidgetState.selected)) return AppColors.surfaceSubtle;
                          return Colors.white;
                        }),
                      ),
                      segments: const [
                        ButtonSegment<bool>(value: false, label: Text('Consume (Gamitin)'), icon: Icon(Icons.build_outlined, size: 15)),
                        ButtonSegment<bool>(value: true, label: Text('Borrow (Hiramin)'), icon: Icon(Icons.handyman_outlined, size: 15)),
                      ],
                      selected: {isBorrow},
                      onSelectionChanged: (set) => setSheetState(() => isBorrow = set.first),
                    ),
                    if (isBorrow) ...[
                      const SizedBox(height: 12),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Loan Duration (Days):', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: AppColors.ink)),
                          DropdownButton<int>(
                            value: days,
                            items: [1, 2, 3, 5, 7, 14].map((d) => DropdownMenuItem(value: d, child: Text('$d day(s)'))).toList(),
                            onChanged: (val) {
                              if (val != null) setSheetState(() => days = val);
                            },
                          ),
                        ],
                      ),
                    ],
                    const SizedBox(height: 16),
                  ],

                  // Out of Stock Section (100% Web Parity)
                  if (isOutOfStock) ...[
                    Container(
                      margin: const EdgeInsets.only(top: 4, bottom: 8),
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: AppColors.redTint,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: AppColors.redBorder, width: 1),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Row(
                            children: [
                              Icon(Icons.error_outline_rounded, color: AppColors.redDanger, size: 20),
                              SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  'This item is currently out of stock.',
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                    color: AppColors.redDanger,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          if (item['loan_count'] != null && (int.tryParse('${item['loan_count']}') ?? 0) > 0) ...[
                            const SizedBox(height: 6),
                            Text(
                              'All ${item['loan_count']} units are currently borrowed. Earliest estimated return: ${item['earliest_due'] ?? 'soon'}.',
                              style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                            ),
                          ],
                          const SizedBox(height: 12),
                          // Primary Notify Button
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: isSubscribed ? AppColors.surfaceSubtle : AppColors.amber,
                              foregroundColor: isSubscribed ? AppColors.inkSoft : Colors.white,
                              side: isSubscribed ? const BorderSide(color: AppColors.line) : BorderSide.none,
                              elevation: isSubscribed ? 0 : 2,
                              padding: const EdgeInsets.symmetric(vertical: 12),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                            ),
                            icon: isTogglingSub || isCheckingSub
                                ? SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: isSubscribed ? AppColors.inkSoft : Colors.white,
                                    ),
                                  )
                                : Icon(
                                    isSubscribed ? Icons.notifications_active : Icons.notifications_none_rounded,
                                    size: 18,
                                    color: isSubscribed ? AppColors.amber : Colors.white,
                                  ),
                            label: Text(
                              isSubscribed ? "Subscribed (We'll notify you)" : 'Notify Me When Available',
                              style: TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 13,
                                color: isSubscribed ? AppColors.ink : Colors.white,
                              ),
                            ),
                            onPressed: (isTogglingSub || isCheckingSub)
                                ? null
                                : () => toggleSub(ctx, setSheetState, selectedVariant),
                          ),
                          const SizedBox(height: 8),
                          // Secondary PO Request Button
                          OutlinedButton(
                            style: OutlinedButton.styleFrom(
                              backgroundColor: Colors.white,
                              foregroundColor: AppColors.ink,
                              side: const BorderSide(color: AppColors.line),
                              padding: const EdgeInsets.symmetric(vertical: 12),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                            ),
                            onPressed: () {
                              Navigator.pop(ctx);
                              final itemTitle = '${item['name'] ?? 'Item'}${item['item_code'] != null ? ' (${item['item_code']})' : ''}';
                              if (widget.onNavigateToSpecialPo != null) {
                                widget.onNavigateToSpecialPo!(defaultItem: itemTitle);
                              } else {
                                showNewItemRequestSheet(
                                  context,
                                  widget.user,
                                  initialItemName: itemTitle,
                                  onSuccess: () => widget.onNavigateToRequests?.call(),
                                );
                              }
                            },
                            child: const Text(
                              'Request Purchase (PO) if needed',
                              style: TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 13,
                                color: AppColors.ink,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ] else ...[
                    // In-Stock Shopee-style Quantity Stepper
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Quantity to Request', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink)),
                            Text(
                              'Max available: $currentStock ${item['unit'] ?? 'pcs'}',
                              style: const TextStyle(fontSize: 11, color: AppColors.inkLight),
                            ),
                          ],
                        ),
                        Row(
                          children: [
                            IconButton.outlined(
                              style: IconButton.styleFrom(side: const BorderSide(color: AppColors.line)),
                              icon: const Icon(Icons.remove, size: 18),
                              onPressed: (qty > 1) ? () => setSheetState(() => qty--) : null,
                            ),
                            Container(
                              width: 48,
                              alignment: Alignment.center,
                              child: Text(
                                '$qty',
                                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
                              ),
                            ),
                            IconButton.outlined(
                              style: IconButton.styleFrom(side: const BorderSide(color: AppColors.line)),
                              icon: const Icon(Icons.add, size: 18),
                              onPressed: (qty < currentStock) ? () => setSheetState(() => qty++) : null,
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // In-Stock Add-to-Cart Action Button
                    ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.amber,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 13),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
                        elevation: 2,
                      ),
                      icon: const Icon(Icons.add_shopping_cart, size: 18),
                      label: Text(
                        'Idagdag sa Requisition Cart ($qty ${item['unit'] ?? 'pcs'})',
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                      ),
                      onPressed: () {
                        globalCart.addItem(CartItem(
                          itemId: item['id'],
                          name: item['name'] ?? 'Item',
                          itemCode: item['item_code'] ?? '',
                          unit: item['unit'] ?? 'pc',
                          quantity: qty,
                          maxStock: currentStock,
                          isBorrowable: isBorrowable,
                          borrowMode: item['borrow_mode'] ?? 'consume',
                          isBorrow: isBorrow,
                          requestedDays: days,
                          variant: selectedVariant,
                          imageUrl: effectiveImg,
                        ));
                        Navigator.pop(ctx);
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text('Naidagdag sa Cart: $qty ${item['name']}${selectedVariant != null ? ' ($selectedVariant)' : ''}'),
                            action: SnackBarAction(
                              label: 'Tingnan ang Cart',
                              textColor: AppColors.amberOnDark,
                              onPressed: _openCartCheckoutSheet,
                            ),
                            duration: const Duration(seconds: 3),
                          ),
                        );
                      },
                    ),
                  ],
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  void _openCartCheckoutSheet() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => CartCheckoutSheet(
        trucks: _trucks,
        user: widget.user,
        onSubmitted: () {
          widget.onNavigateToRequests?.call();
        },
      ),
    );
  }

  // ---------------------------------------------------------
  // Shopee-style 2-Column Product Grid Card
  // ---------------------------------------------------------
  Widget _buildShopeeProductCard(Map<String, dynamic> item) {
    final stock = int.tryParse((item['stock'] ?? item['quantity_on_hand'] ?? 0).toString()) ?? 0;
    final isOutOfStock = stock <= 0;
    final isBorrowable = item['is_borrowable'] == true ||
        item['is_borrowable'] == 1 ||
        item['borrow_mode'] == 'borrow' ||
        item['borrow_mode'] == 'either';
    final itemImgUrl = AppConfig.resolveImageUrl(
      item['image_url'],
      filename: item['image_filename'],
      activeBaseUrl: _baseUrl,
    );
    final hasVariants = (item['variants'] as List<dynamic>?)?.isNotEmpty ?? false;

    return Card(
      elevation: 1,
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: const BorderSide(color: AppColors.line, width: 0.8),
      ),
      child: InkWell(
        onTap: () => _openAddToCartSheet(item),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Top Product Picture (Full-bleed with Shopee Badges)
            Expanded(
              flex: 12,
              child: Stack(
                children: [
                  Positioned.fill(
                    child: AppItemImage(
                      imageUrl: itemImgUrl,
                      width: double.infinity,
                      height: double.infinity,
                      borderRadius: BorderRadius.zero,
                      fit: BoxFit.cover,
                    ),
                  ),
                  // Out of Stock Overlay
                  if (isOutOfStock)
                    Positioned.fill(
                      child: Container(
                        color: Colors.black.withOpacity(0.42),
                        child: Center(
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3.5),
                            decoration: BoxDecoration(
                              color: AppColors.redDanger.withOpacity(0.92),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Text(
                              'OUT OF STOCK',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 9.5,
                                fontWeight: FontWeight.bold,
                                letterSpacing: 0.4,
                              ),
                            ),
                          ),
                        ),
                      ),
                    )
                  else ...[
                    // Borrowable Badge (Top Left)
                    if (isBorrowable)
                      Positioned(
                        top: 6,
                        left: 6,
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.amber.withOpacity(0.92),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: const Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.handyman, size: 9, color: Colors.white),
                              SizedBox(width: 3),
                              Text(
                                'Borrowable',
                                style: TextStyle(color: Colors.white, fontSize: 8.5, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                      ),
                    // Variants Badge (Top Right)
                    if (hasVariants)
                      Positioned(
                        top: 6,
                        right: 6,
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.65),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: const Text(
                            'Variants',
                            style: TextStyle(color: Colors.white, fontSize: 8.5, fontWeight: FontWeight.w600),
                          ),
                        ),
                      ),
                  ],
                ],
              ),
            ),

            // Shopee Product Info & Stock (Well-spaced & Proportional)
            Expanded(
              flex: 11,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(9, 7, 9, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item['name'] ?? 'Item',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: AppColors.ink,
                            height: 1.2,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${item['item_code'] ?? 'N/A'} • ${item['category_name'] ?? 'General'}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 9.5, color: AppColors.inkSoft),
                        ),
                      ],
                    ),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: isOutOfStock ? AppColors.redTint : AppColors.greenTint,
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: isOutOfStock ? AppColors.redBorder : AppColors.greenBorder),
                          ),
                          child: Text(
                            isOutOfStock ? 'No Stock' : 'Stock: $stock ${item['unit'] ?? 'pc'}',
                            style: TextStyle(
                              color: isOutOfStock ? AppColors.redDanger : AppColors.greenOk,
                              fontSize: 9.5,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        Container(
                          width: 24,
                          height: 24,
                          decoration: BoxDecoration(
                            color: isOutOfStock ? AppColors.surfaceSubtle : AppColors.amber,
                            shape: BoxShape.circle,
                          ),
                          child: Icon(
                            isOutOfStock ? Icons.remove_red_eye_outlined : Icons.add_shopping_cart,
                            size: 13,
                            color: isOutOfStock ? AppColors.inkSoft : Colors.white,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ---------------------------------------------------------
  // Shopee-style Horizontal List Card (Alternative View)
  // ---------------------------------------------------------
  Widget _buildShopeeListCard(Map<String, dynamic> item) {
    final stock = int.tryParse((item['stock'] ?? item['quantity_on_hand'] ?? 0).toString()) ?? 0;
    final isOutOfStock = stock <= 0;
    final itemImgUrl = AppConfig.resolveImageUrl(
      item['image_url'],
      filename: item['image_filename'],
      activeBaseUrl: _baseUrl,
    );

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      elevation: 0.8,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(9),
        side: const BorderSide(color: AppColors.line, width: 0.8),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(9),
        onTap: () => _openAddToCartSheet(item),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              AppItemImage(
                imageUrl: itemImgUrl,
                width: 58,
                height: 58,
                borderRadius: BorderRadius.circular(8),
                fit: BoxFit.cover,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item['name'] ?? 'Item',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: AppColors.ink),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Code: ${item['item_code'] ?? 'N/A'} • ${item['category_name'] ?? 'General'}',
                      style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                          decoration: BoxDecoration(
                            color: isOutOfStock ? AppColors.redTint : AppColors.greenTint,
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: isOutOfStock ? AppColors.redBorder : AppColors.greenBorder),
                          ),
                          child: Text(
                            isOutOfStock ? 'Out of Stock' : 'Stock: $stock ${item['unit'] ?? 'pcs'}',
                            style: TextStyle(
                              color: isOutOfStock ? AppColors.redDanger : AppColors.greenOk,
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        const Spacer(),
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            backgroundColor: isOutOfStock ? AppColors.surfaceSubtle : AppColors.amber,
                            foregroundColor: isOutOfStock ? AppColors.inkSoft : Colors.white,
                            elevation: 0,
                          ),
                          onPressed: () => _openAddToCartSheet(item),
                          child: Text(isOutOfStock ? 'View' : 'Request', style: const TextStyle(fontSize: 12)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.t('cat_title'),
        user: widget.user,
        actions: [
          ListenableBuilder(
            listenable: globalCart,
            builder: (ctx, _) {
              final count = globalCart.count;
              return Stack(
                alignment: Alignment.center,
                children: [
                  IconButton(
                    icon: const Icon(Icons.shopping_cart_outlined, color: Colors.white),
                    onPressed: _openCartCheckoutSheet,
                    tooltip: 'Cart',
                  ),
                  if (count > 0)
                    Positioned(
                      top: 6,
                      right: 6,
                      child: Container(
                        padding: const EdgeInsets.all(3),
                        decoration: const BoxDecoration(
                          color: AppColors.redDanger,
                          shape: BoxShape.circle,
                        ),
                        constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                        child: Text(
                          '$count',
                          style: const TextStyle(color: Colors.white, fontSize: 9.5, fontWeight: FontWeight.bold),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _fetchCatalog,
            tooltip: globalLanguage.t('refresh'),
          ),
        ],
      ),
      floatingActionButton: ListenableBuilder(
        listenable: globalCart,
        builder: (ctx, _) {
          final count = globalCart.count;
          if (count == 0) return const SizedBox.shrink();
          return FloatingActionButton.extended(
            backgroundColor: AppColors.amber,
            foregroundColor: Colors.white,
            elevation: 4,
            onPressed: _openCartCheckoutSheet,
            icon: Badge(
              isLabelVisible: count > 0,
              label: Text('$count'),
              backgroundColor: AppColors.charcoal,
              child: const Icon(Icons.shopping_cart_checkout),
            ),
            label: Text(globalLanguage.isTagalog ? 'Tingnan ang Cart ($count)' : 'View Cart ($count)'),
          );
        },
      ),
      body: Column(
        children: [
          if (_isOffline)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: AppColors.amberTint,
              child: Row(
                children: [
                  const Icon(Icons.cloud_off, size: 16, color: AppColors.amber),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      globalLanguage.isTagalog
                          ? 'Offline Mode: Naka-cache na katalogo ang ipinapakita.'
                          : 'Offline Mode: Showing cached catalog only.',
                      style: const TextStyle(fontSize: 12, color: AppColors.amber, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),

          // Search Bar & Filter Header
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 10),
            child: TextField(
              decoration: InputDecoration(
                hintText: globalLanguage.t('cat_search_hint'),
                hintStyle: const TextStyle(fontSize: 13, color: AppColors.inkLight),
                prefixIcon: const Icon(Icons.search, size: 18, color: AppColors.inkSoft),
                filled: true,
                fillColor: AppColors.paper,
                contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              ),
              onChanged: (val) {
                _searchQuery = val;
                _applyFilter();
              },
            ),
          ),

          // Category Chips
          Container(
            color: AppColors.surface,
            height: 44,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              scrollDirection: Axis.horizontal,
              itemCount: _categories.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (ctx, idx) {
                final cat = _categories[idx];
                final isSelected = cat == _selectedCategory;
                return ChoiceChip(
                  label: Text(cat),
                  selected: isSelected,
                  selectedColor: AppColors.surfaceSubtle,
                  backgroundColor: AppColors.paper,
                  side: BorderSide(color: isSelected ? AppColors.amber : AppColors.line),
                  labelStyle: TextStyle(
                    color: isSelected ? AppColors.amber : AppColors.ink,
                    fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                    fontSize: 12,
                  ),
                  onSelected: (selected) {
                    if (selected) {
                      _selectedCategory = cat;
                      _applyFilter();
                    }
                  },
                );
              },
            ),
          ),
          const Divider(height: 1, color: AppColors.line),

          // Results count banner & Shopee Grid/List view toggle
          Container(
            color: AppColors.paper,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  globalLanguage.isTagalog
                      ? '${_filteredItems.length} mga gamit sa catalog'
                      : '${_filteredItems.length} items in catalog',
                  style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.inkSoft,
                  ),
                ),
                Row(
                  children: [
                    InkWell(
                      borderRadius: BorderRadius.circular(6),
                      onTap: () => setState(() => _isGridView = true),
                      child: Container(
                        padding: const EdgeInsets.all(5),
                        decoration: BoxDecoration(
                          color: _isGridView ? AppColors.surfaceSubtle : Colors.transparent,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: _isGridView ? AppColors.amber : AppColors.line),
                        ),
                        child: Icon(
                          Icons.grid_view_rounded,
                          size: 16,
                          color: _isGridView ? AppColors.amber : AppColors.inkSoft,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    InkWell(
                      borderRadius: BorderRadius.circular(6),
                      onTap: () => setState(() => _isGridView = false),
                      child: Container(
                        padding: const EdgeInsets.all(5),
                        decoration: BoxDecoration(
                          color: !_isGridView ? AppColors.surfaceSubtle : Colors.transparent,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: !_isGridView ? AppColors.amber : AppColors.line),
                        ),
                        child: Icon(
                          Icons.view_list_rounded,
                          size: 16,
                          color: !_isGridView ? AppColors.amber : AppColors.inkSoft,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // Catalog Content (Shopee Grid by default, or List View)
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
                : _filteredItems.isEmpty
                    ? Center(child: Text(globalLanguage.isTagalog ? 'Walang nahanap na gamit.' : 'No items found.', style: const TextStyle(color: AppColors.inkSoft)))
                    : _isGridView
                        ? GridView.builder(
                            padding: const EdgeInsets.fromLTRB(14, 4, 14, 84),
                            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 2,
                              crossAxisSpacing: 10,
                              mainAxisSpacing: 10,
                              childAspectRatio: 0.72,
                            ),
                            itemCount: _filteredItems.length,
                            itemBuilder: (ctx, idx) => _buildShopeeProductCard(_filteredItems[idx]),
                          )
                        : ListView.builder(
                            padding: const EdgeInsets.fromLTRB(14, 4, 14, 84),
                            itemCount: _filteredItems.length,
                            itemBuilder: (ctx, idx) => _buildShopeeListCard(_filteredItems[idx]),
                          ),
          ),
        ],
      ),
    );
  }
}
class CartCheckoutSheet extends StatefulWidget {
  final List<dynamic> trucks;
  final Map<String, dynamic> user;
  final VoidCallback onSubmitted;

  const CartCheckoutSheet({super.key, required this.trucks, required this.user, required this.onSubmitted});

  @override
  State<CartCheckoutSheet> createState() => _CartCheckoutSheetState();
}

class _CartCheckoutSheetState extends State<CartCheckoutSheet> {
  int? _selectedTruckId;
  // True when the parts are to repair the selected truck (brake pads, bulb,
  // rim...), not for a trip. Sent to the server as is_maintenance_request.
  bool _isRepairRequest = false;
  String _urgency = 'routine';
  final _purposeCtrl = TextEditingController(text: 'Routine fleet replenishment');
  bool _isSubmitting = false;
  String? _validationError;
  bool get isOfficeStaff => (widget.user['position'] ?? '').toString().toLowerCase() == 'office_staff';

  Future<void> _submitBatchRequisition() async {
    final cartItems = globalCart.items;
    if (cartItems.isEmpty) {
      setState(() => _validationError = globalLanguage.t('cat_cart_empty'));
      return;
    }

    final isOfficeStaff = (widget.user['position'] ?? '').toString().toLowerCase() == 'office_staff';

    // MANDATORY TRUCK VALIDATION (Exempted for Office Staff)
    if (!isOfficeStaff && (_selectedTruckId == null || _selectedTruckId! <= 0)) {
      setState(() => _validationError = globalLanguage.isTagalog
          ? 'Pakiusap, pumili ng Truck Plate! Mandatory ito para sa fleet logistics.'
          : 'Please select a Vehicle Plate! Mandatory for fleet logistics.');
      return;
    }

    setState(() {
      _isSubmitting = true;
      _validationError = null;
    });

    final isUrgent = (_urgency == 'urgent' || _urgency == 'breakdown') ? 1 : 0;
    final payloadItems = cartItems.map((c) => c.toJson()).toList();

    final reqPayload = {
      'user_id': widget.user['id'],
      'token': widget.user['token'] ?? '',
      'truck_id': _selectedTruckId,
      'is_maintenance_request': (_selectedTruckId != null && _isRepairRequest) ? 1 : 0,
      'purpose': _purposeCtrl.text.trim(),
      'is_urgent': isUrgent,
      'items': payloadItems,
      'urgency_label': _urgency,
    };

    http.Response? res;
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode(reqPayload),
      ).timeout(const Duration(seconds: 8));
    } catch (_) {
      // Genuinely offline or connection timed out
      await AppConfig.queueOfflineRequisition(reqPayload);
      globalCart.clear();
      if (!mounted) return;
      Navigator.pop(context);
      widget.onSubmitted();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Offline: Naka-save sa local queue ang iyong Requisition Batch!'),
          backgroundColor: AppColors.blueInfo,
        ),
      );
      if (mounted) setState(() => _isSubmitting = false);
      return;
    }

    try {
      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        globalCart.clear();
        if (!mounted) return;
        Navigator.pop(context);
        widget.onSubmitted();
        final reqId = data['data']?['requisition_id'] ?? '';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Matagumpay na naisumite ang Requisition${reqId != '' ? ' #$reqId' : ''}!'),
            backgroundColor: AppColors.greenOk,
          ),
        );
      } else {
        if (!mounted) return;
        final errMsg = data['error'] ?? data['message'] ?? 'Hindi naisumite ang kahilingan.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(errMsg.toString()),
            backgroundColor: AppColors.redDanger,
          ),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Server Error: ${res.statusCode}'),
          backgroundColor: AppColors.redDanger,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
      ),
      padding: EdgeInsets.fromLTRB(20, 16, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: ListenableBuilder(
        listenable: globalCart,
        builder: (ctx, _) {
          final cartItems = globalCart.items;

          return SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 36,
                    height: 4,
                    decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
                  ),
                ),
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      globalLanguage.isTagalog
                          ? 'Requisition Cart (${globalCart.count} gamit)'
                          : 'Requisition Cart (${globalCart.count} items)',
                      style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
                    ),
                    if (cartItems.isNotEmpty)
                      TextButton(
                        onPressed: () => globalCart.clear(),
                        child: Text(
                          globalLanguage.isTagalog ? 'Alisin Lahat' : 'Clear Cart',
                          style: const TextStyle(color: AppColors.redDanger, fontSize: 12),
                        ),
                      ),
                  ],
                ),
                const Divider(color: AppColors.line),

                if (_validationError != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 14),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.redTint,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: AppColors.redBorder),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.error_outline, color: AppColors.redDanger, size: 18),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            _validationError!,
                            style: const TextStyle(color: AppColors.redDanger, fontSize: 12, fontWeight: FontWeight.w600),
                          ),
                        ),
                      ],
                    ),
                  ),

                if (cartItems.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 28),
                    child: Center(
                      child: Column(
                        children: [
                          const Icon(Icons.shopping_cart_outlined, size: 42, color: AppColors.inkLight),
                          const SizedBox(height: 8),
                          Text(globalLanguage.t('cat_cart_empty'), style: const TextStyle(color: AppColors.inkSoft)),
                        ],
                      ),
                    ),
                  )
                else ...[
                  // Item list
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 220),
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: cartItems.length,
                      separatorBuilder: (_, __) => const Divider(height: 8, color: AppColors.line),
                      itemBuilder: (ctx, idx) {
                        final item = cartItems[idx];
                        return Row(
                          children: [
                            AppItemImage(
                              imageUrl: item.imageUrl,
                              width: 36,
                              height: 36,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(item.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink)),
                                  Text(
                                    '${item.itemCode} • ${item.isBorrow ? 'BORROW (${item.requestedDays} days)' : 'CONSUME'}${item.variant != null ? ' • ${item.variant}' : ''}',
                                    style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                                  ),
                                ],
                              ),
                            ),
                            IconButton(
                              icon: const Icon(Icons.remove_circle_outline, size: 18, color: AppColors.inkSoft),
                              onPressed: () => globalCart.updateQuantity(item.itemId, item.quantity - 1),
                            ),
                            Text('${item.quantity}', style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.ink)),
                            IconButton(
                              icon: const Icon(Icons.add_circle_outline, size: 18, color: AppColors.inkSoft),
                              onPressed: () => globalCart.updateQuantity(item.itemId, item.quantity + 1),
                            ),
                            IconButton(
                              icon: const Icon(Icons.delete_outline, size: 18, color: AppColors.redDanger),
                              onPressed: () => globalCart.removeItem(item.itemId),
                            ),
                          ],
                        );
                      },
                    ),
                  ),

                  const SizedBox(height: 14),

                  // MANDATORY FLEET TRUCK SELECTOR (Exempted for Office Staff)
                  if (!isOfficeStaff) ...[
                    Row(
                      children: [
                        Text(globalLanguage.choice('Nakatakdang Fleet Truck', 'Assigned Fleet Truck'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink)),
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                          decoration: BoxDecoration(color: AppColors.redTint, borderRadius: BorderRadius.circular(4)),
                          child: Text(globalLanguage.choice('KAILANGAN *', 'REQUIRED *'), style: const TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: AppColors.redDanger)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    DropdownButtonFormField<int?>(
                      value: _selectedTruckId,
                      decoration: InputDecoration(
                        hintText: globalLanguage.choice('Piliin ang Truck Plate ng Sasakyan...', 'Select Truck Plate Number...'),
                      ),
                      items: [
                        ...widget.trucks.map((t) {
                          final id = int.tryParse('${t['id']}');
                          final plate = t['plate_number'] ?? '';
                          final model = t['model'] ?? '';
                          final st = '${t['status'] ?? ''}';
                          final stLabel = st == 'under_maintenance'
                              ? globalLanguage.choice(' • Nasa Pag-aayos', ' • Under Maintenance')
                              : (st == 'on_trip' ? globalLanguage.choice(' • Nasa Biyahe', ' • On Trip') : '');
                          return DropdownMenuItem(
                            value: id,
                            child: Text('$plate ($model)$stLabel'),
                          );
                        }),
                      ],
                      onChanged: (val) => setState(() {
                        _selectedTruckId = val;
                        _validationError = null;
                        // A truck under maintenance can only take repair
                        // requests, so pre-check the box for it.
                        final sel = widget.trucks.firstWhere(
                          (t) => int.tryParse('${t['id']}') == val,
                          orElse: () => null,
                        );
                        _isRepairRequest = sel != null && '${sel['status']}' == 'under_maintenance';
                      }),
                    ),
                    if (_selectedTruckId != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        globalLanguage.choice('Uri ng Kahilingan para sa Truck', 'Requisition Type for Vehicle'),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12.5, color: AppColors.ink),
                      ),
                      const SizedBox(height: 6),
                      // Option 1: Trip Gear
                      InkWell(
                        onTap: () {
                          final sel = widget.trucks.firstWhere(
                            (t) => int.tryParse('${t['id']}') == _selectedTruckId,
                            orElse: () => null,
                          );
                          if (sel != null && '${sel['status']}' == 'under_maintenance') {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(globalLanguage.choice(
                                  'Hindi maaaring pumili ng Gamit sa Byahe dahil ang truck ay kasalukuyang Under Maintenance.',
                                  'Cannot select Trip Gear because this truck is currently Under Maintenance.',
                                )),
                                backgroundColor: AppColors.amber,
                              ),
                            );
                            return;
                          }
                          setState(() => _isRepairRequest = false);
                        },
                        borderRadius: BorderRadius.circular(8),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          decoration: BoxDecoration(
                            color: !_isRepairRequest ? AppColors.surfaceSubtle : AppColors.surface,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: !_isRepairRequest ? AppColors.amber : AppColors.line),
                          ),
                          child: Row(
                            children: [
                              Icon(
                                !_isRepairRequest ? Icons.radio_button_checked : Icons.radio_button_off,
                                size: 18,
                                color: !_isRepairRequest ? AppColors.amber : AppColors.inkSoft,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      globalLanguage.choice('🚛 Gamit sa Byahe / Trip Requisition', '🚛 Trip Gear / Hauling Dispatch'),
                                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: !_isRepairRequest ? AppColors.amberDim : AppColors.ink),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      globalLanguage.choice(
                                        'Mga kagamitan at gamit ng crew para sa takdang biyahe ng truck.',
                                        'Crew equipment, tools, and supplies for vehicle dispatch run.',
                                      ),
                                      style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 6),
                      // Option 2: Repair & Maintenance
                      InkWell(
                        onTap: () => setState(() => _isRepairRequest = true),
                        borderRadius: BorderRadius.circular(8),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          decoration: BoxDecoration(
                            color: _isRepairRequest ? AppColors.blueTint : AppColors.surface,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: _isRepairRequest ? AppColors.blueInfo : AppColors.line),
                          ),
                          child: Row(
                            children: [
                              Icon(
                                _isRepairRequest ? Icons.radio_button_checked : Icons.radio_button_off,
                                size: 18,
                                color: _isRepairRequest ? AppColors.blueInfo : AppColors.inkSoft,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      globalLanguage.choice('🔧 Kumpuni at Pyesa / Vehicle Repair & Parts', '🔧 Vehicle Repair & Maintenance Parts'),
                                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: _isRepairRequest ? AppColors.blueInfo : AppColors.ink),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      globalLanguage.choice(
                                        'Pyesa, langis, o PMS para sa pagkukumpuni ng mismong sasakyan.',
                                        'Parts, oil, or PMS specifically to repair or service this vehicle.',
                                      ),
                                      style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      if (() {
                        final sel = widget.trucks.firstWhere(
                          (t) => int.tryParse('${t['id']}') == _selectedTruckId,
                          orElse: () => null,
                        );
                        return sel != null && '${sel['status']}' == 'under_maintenance';
                      }()) ...[
                        const SizedBox(height: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: AppColors.amberTint,
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: AppColors.amberBorder),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.amber),
                              const SizedBox(width: 6),
                              Expanded(
                                child: Text(
                                  globalLanguage.choice(
                                    'Paalala: Naka-Under Maintenance ang truck na ito. Naka-lock sa Pyesa/Kumpuni dahil hindi pa maaaring ibiyahe.',
                                    'Notice: This vehicle is Under Maintenance. Locked to Repair/Parts because it cannot be dispatched on trips.',
                                  ),
                                  style: const TextStyle(fontSize: 11, color: AppColors.amberDim, fontWeight: FontWeight.w500),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ],
                    const SizedBox(height: 12),
                  ],

                  // Urgency
                  Text(globalLanguage.choice('Lebel ng Pangangailangan', 'Urgency Level'), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink)),
                  const SizedBox(height: 6),
                  DropdownButtonFormField<String>(
                    value: _urgency,
                    items: [
                      DropdownMenuItem(value: 'routine', child: Text(globalLanguage.choice('Routine / Nakaiskedyul na Pagpapalit', 'Routine / Scheduled Replacement'))),
                      DropdownMenuItem(value: 'urgent', child: Text(globalLanguage.choice('Urgent / Paalis na Biyahe', 'Urgent Operation (Trip Departure)'))),
                      DropdownMenuItem(value: 'breakdown', child: Text(globalLanguage.choice('Kritikal / Emergency Nasiraan sa Daan', 'Critical / Emergency Road Breakdown'))),
                    ],
                    onChanged: (val) {
                      if (val != null) setState(() => _urgency = val);
                    },
                  ),
                  const SizedBox(height: 12),

                  // Purpose / Reason presets & input
                  Text(
                    globalLanguage.isTagalog ? 'Layunin / Dahilan ng Pag-request:' : 'Purpose / Request Reason:',
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
                  ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 5,
                    children: [
                      globalLanguage.isTagalog ? 'Delivery Run / Biyahe' : 'Delivery Run / Trip',
                      globalLanguage.isTagalog ? 'Routine PMS / Change Oil' : 'Routine Maintenance (PMS)',
                      globalLanguage.isTagalog ? 'Emergency Nasiraan sa Daan' : 'Emergency Breakdown Repair',
                      globalLanguage.isTagalog ? 'Site Work / Unloading' : 'Project Site Operations',
                      globalLanguage.isTagalog ? 'Restock Truck Supplies' : 'Restock Truck Supplies',
                      globalLanguage.isTagalog ? 'Iba pa (Custom)' : 'Other (Custom)',
                    ].map((r) {
                      final isCustom = r.startsWith('Iba pa') || r.startsWith('Other');
                      final isSelected = isCustom
                          ? (_purposeCtrl.text.isNotEmpty && ![
                              'Delivery Run / Biyahe',
                              'Delivery Run / Trip',
                              'Routine PMS / Change Oil',
                              'Routine Maintenance (PMS)',
                              'Emergency Nasiraan sa Daan',
                              'Emergency Breakdown Repair',
                              'Site Work / Unloading',
                              'Project Site Operations',
                              'Restock Truck Supplies',
                              'Routine fleet replenishment',
                            ].contains(_purposeCtrl.text))
                          : _purposeCtrl.text == r;
                      return InkWell(
                        onTap: () {
                          setState(() {
                            if (isCustom) {
                              _purposeCtrl.text = '';
                            } else {
                              _purposeCtrl.text = r;
                            }
                          });
                        },
                        borderRadius: BorderRadius.circular(16),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                          decoration: BoxDecoration(
                            color: isSelected ? AppColors.amberTint : AppColors.surfaceSubtle,
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: isSelected ? AppColors.amber : AppColors.line),
                          ),
                          child: Text(
                            r,
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                              color: isSelected ? AppColors.amber : AppColors.inkSoft,
                            ),
                          ),
                        ),
                      );
                    }).toList(),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _purposeCtrl,
                    maxLines: 2,
                    decoration: InputDecoration(
                      hintText: globalLanguage.isTagalog
                          ? 'Pumili sa itaas o mag-type ng sariling dahilan...'
                          : 'Select above or type custom reason...',
                    ),
                  ),
                  const SizedBox(height: 18),

                  ElevatedButton(
                    onPressed: _isSubmitting ? null : _submitBatchRequisition,
                    child: _isSubmitting
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : Text(globalLanguage.choice('I-submit ang Requisition Batch (${globalCart.count} items)', 'Submit Requisition Batch (${globalCart.count} items)')),
                  ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------
// Tab 2: My Requisitions & Interactive QR Slip Viewer
// ---------------------------------------------------------
// ---------------------------------------------------------
// Live Rotating Handshake QR Generator & Widget (Anti-Screenshot Dual-Custody)
// ---------------------------------------------------------
class DynamicHandshakeHelper {
  static String generate(String qrToken, dynamic userId) {
    final uid = userId?.toString() ?? '0';
    final slice = DateTime.now().millisecondsSinceEpoch ~/ 30000;
    final raw = '$qrToken:$uid:$slice:duarte_pos_handshake';
    final bytes = utf8.encode(raw);
    final sig = sha256.convert(bytes).toString().substring(0, 12);
    return '$slice:$sig';
  }
}

class DynamicPickupQrWidget extends StatefulWidget {
  final String qrToken;
  final dynamic userId;
  const DynamicPickupQrWidget({super.key, required this.qrToken, required this.userId});

  @override
  State<DynamicPickupQrWidget> createState() => _DynamicPickupQrWidgetState();
}

class _DynamicPickupQrWidgetState extends State<DynamicPickupQrWidget> {
  Timer? _timer;
  int _secondsRemaining = 30;

  @override
  void initState() {
    super.initState();
    _updateSeconds();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {
          _updateSeconds();
        });
      }
    });
  }

  void _updateSeconds() {
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    _secondsRemaining = 30 - (now % 30);
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final handshake = DynamicHandshakeHelper.generate(widget.qrToken, widget.userId);
    final livePayload = '${widget.qrToken}#$handshake';
    final progress = _secondsRemaining / 30.0;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Center(
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.line, width: 2),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.06),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: QrImageView(
              data: livePayload,
              version: QrVersions.auto,
              size: 155.0,
            ),
          ),
        ),
        const SizedBox(height: 10),
        // Live Dual-Custody Handshake Progress Badge
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            color: AppColors.greenTint,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: AppColors.greenBorder),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                width: 13,
                height: 13,
                child: CircularProgressIndicator(
                  value: progress,
                  strokeWidth: 2.2,
                  valueColor: const AlwaysStoppedAnimation<Color>(AppColors.greenOk),
                  backgroundColor: AppColors.greenBorder,
                ),
              ),
              const SizedBox(width: 7),
              Text(
                globalLanguage.choice(
                  '⚡ Live Handshake • Magre-refresh sa ${_secondsRemaining}s',
                  '⚡ Live Handshake • Refreshes in ${_secondsRemaining}s',
                ),
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.greenOk),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Center(
          child: Text(
            globalLanguage.choice(
              'Iharap ang live QR code sa bodega counter para sa mabilisang verification nang walang PIN.',
              'Present this live QR code at the warehouse counter for instant verification without PIN.',
            ),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------
// Tab 2: Requisitions & Tool Loans Screen (Full Web Parity)
// ---------------------------------------------------------
class MyRequisitionsScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final String? initialTab;
  final int? initialSubSection;
  const MyRequisitionsScreen({super.key, required this.user, this.initialTab, this.initialSubSection});

  @override
  State<MyRequisitionsScreen> createState() => _MyRequisitionsScreenState();
}

class _MyRequisitionsScreenState extends State<MyRequisitionsScreen> {
  String _baseUrl = '';
  int _subSection = 0; // 0 = Requisitions, 1 = Borrowed Tools (Loans)
  List<dynamic> _requests = [];
  List<dynamic> _loans = [];
  bool _isLoading = true;
  bool _isLoadingLoans = false;
  String _filterTab = 'All';

  @override
  void initState() {
    super.initState();
    if (widget.initialTab != null) {
      _filterTab = widget.initialTab!;
    }
    if (widget.initialSubSection != null) {
      _subSection = widget.initialSubSection!;
    }
    _fetchRequests();
    _fetchLoans();
  }

  @override
  void didUpdateWidget(covariant MyRequisitionsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.initialTab != null && widget.initialTab != oldWidget.initialTab) {
      setState(() => _filterTab = widget.initialTab!);
      _fetchRequests();
    }
    if (widget.initialSubSection != null && widget.initialSubSection != oldWidget.initialSubSection) {
      setState(() => _subSection = widget.initialSubSection!);
    }
  }

  Future<void> _fetchRequests() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      _baseUrl = baseUrl;
      String query = 'user_id=${widget.user['id']}&role=${widget.user['role']}&token=${widget.user['token'] ?? ''}';
      if (_filterTab == 'Approvals') {
        query += '&tab=pending';
      } else {
        query += '&tab=all';
      }
      final url = Uri.parse('$baseUrl/requisitions.php?$query');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (mounted) {
          setState(() => _requests = payload is List ? payload : []);
        }
      } else {
        debugPrint('[MyRequisitions._fetchRequests] HTTP ${res.statusCode}: ${res.body}');
        if (mounted && res.statusCode == 401) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(globalLanguage.choice('Na-expire o invalid na ang session mo — mag-login ulit.', 'Session expired or invalid — please log in again.'))),
          );
        }
      }
    } catch (e) {
      debugPrint('[MyRequisitions._fetchRequests] failed: $e');
    }
    if (mounted) setState(() => _isLoading = false);
  }

  Future<void> _fetchLoans() async {
    setState(() => _isLoadingLoans = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/loans.php?user_id=${widget.user['id']}&role=${widget.user['role']}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (mounted) {
          setState(() => _loans = payload is List ? payload : []);
        }
      } else {
        debugPrint('[MyRequisitions._fetchLoans] HTTP ${res.statusCode}: ${res.body}');
      }
    } catch (e) {
      debugPrint('[MyRequisitions._fetchLoans] failed: $e');
    }
    if (mounted) setState(() => _isLoadingLoans = false);
  }

  List<dynamic> get _filteredRequests {
    if (_filterTab == 'All' || _filterTab == 'Approvals') return _requests;
    if (_filterTab == 'Pending') return _requests.where((r) => (r['status'] ?? '').toString().toLowerCase() == 'pending').toList();
    if (_filterTab == 'Approved') return _requests.where((r) => ['approved', 'ready_for_pickup'].contains((r['status'] ?? '').toString().toLowerCase())).toList();
    if (_filterTab == 'Released') return _requests.where((r) => (r['status'] ?? '').toString().toLowerCase() == 'released').toList();
    return _requests;
  }

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'ready_for_pickup':
      case 'released':
      case 'issued':
        return AppColors.greenOk;
      case 'pending':
      case 'extension_pending':
        return AppColors.amber;
      case 'declined':
      case 'cancelled':
      case 'rejected':
        return AppColors.redDanger;
      default:
        return AppColors.blueInfo;
    }
  }

  Color _getStatusBg(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'ready_for_pickup':
      case 'released':
      case 'issued':
        return AppColors.greenTint;
      case 'pending':
      case 'extension_pending':
        return AppColors.amberTint;
      case 'declined':
      case 'cancelled':
      case 'rejected':
        return AppColors.redTint;
      default:
        return AppColors.blueTint;
    }
  }

  Color _getStatusBorder(String status) {
    switch (status.toLowerCase()) {
      case 'approved':
      case 'ready_for_pickup':
      case 'released':
      case 'issued':
        return AppColors.greenBorder;
      case 'pending':
      case 'extension_pending':
        return AppColors.amberBorder;
      case 'declined':
      case 'cancelled':
      case 'rejected':
        return AppColors.redBorder;
      default:
        return AppColors.blueBorder;
    }
  }

  Future<void> _cancelRequisition(int reqId) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(
          globalLanguage.isTagalog ? 'Kanselahin ang Requisition?' : 'Cancel Requisition?',
          style: const TextStyle(color: AppColors.ink, fontWeight: FontWeight.bold),
        ),
        content: Text(
          globalLanguage.isTagalog
              ? 'Sigurado ka bang nais mong kanselahin ang request na ito? Hindi na ito mababawi kapag nakansela.'
              : 'Are you sure you want to cancel this requisition? This cannot be undone once cancelled.',
          style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(globalLanguage.isTagalog ? 'Huwag Ituloy' : 'Keep Request', style: const TextStyle(color: AppColors.inkSoft)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.redDanger, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(globalLanguage.isTagalog ? 'Kanselahin' : 'Cancel Requisition'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'cancel',
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
          'requisition_id': reqId,
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        Navigator.pop(context); // close slip sheet
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(globalLanguage.choice('Matagumpay na nakansela ang requisition.', 'Requisition successfully cancelled.'))),
        );
        _fetchRequests();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(data['error'] ?? 'Hindi nakansela ang requisition.'), backgroundColor: AppColors.redDanger),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(globalLanguage.isTagalog
              ? 'Hindi makakansela sa ngayon. Pakisubukan muli.'
              : 'Cancellation failed. Please try again.'),
          backgroundColor: AppColors.redDanger,
        ),
      );
    }
  }

  Future<void> _decideRequisition(int reqId, String decision) async {
    final noteCtrl = TextEditingController();
    final isApprove = decision == 'approved';

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(
          isApprove
              ? globalLanguage.choice('Aprubahan ang Requisition #$reqId', 'Approve Requisition #$reqId')
              : globalLanguage.choice('Tanggihan ang Requisition #$reqId', 'Decline Requisition #$reqId'),
          style: TextStyle(color: isApprove ? AppColors.greenOk : AppColors.redDanger, fontWeight: FontWeight.bold),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              isApprove
                  ? globalLanguage.choice(
                      'Ilagay ang optional na supervisor approval note para sa warehouse release:',
                      'Enter optional supervisor approval note for warehouse release:',
                    )
                  : globalLanguage.choice(
                      'Ilagay ang dahilan kung bakit tinanggihan ang request na ito:',
                      'Enter reason why this request is being declined:',
                    ),
              style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: noteCtrl,
              maxLines: 2,
              decoration: InputDecoration(
                hintText: isApprove
                    ? globalLanguage.choice('Hal. Inaprubahan para sa site deployment', 'E.g. Approved for site deployment')
                    : globalLanguage.choice('Hal. Ubos na ang stock / Gamitin ang alternatibong tool', 'E.g. Out of stock / Use alternative tool'),
                hintStyle: const TextStyle(fontSize: 12),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(globalLanguage.choice('Bumalik', 'Back'), style: const TextStyle(color: AppColors.inkSoft)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: isApprove ? AppColors.greenOk : AppColors.redDanger,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(isApprove ? globalLanguage.t('approve') : globalLanguage.t('reject')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');
      final res = await http.post(
        url,
        headers: AppConfig.authHeaders(widget.user),
        body: jsonEncode({
          'action': 'decide',
          'user_id': widget.user['id'],
          'token': widget.user['token'] ?? '',
          'requisition_id': reqId,
          'decision': decision,
          'decision_note': noteCtrl.text.trim(),
        }),
      );

      final data = jsonDecode(res.body);
      if (res.statusCode == 200 && data['success'] == true) {
        if (!mounted) return;
        Navigator.pop(context); // close slip sheet
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(globalLanguage.choice(
              'Matagumpay na na-$decision ang requisition #$reqId.',
              'Requisition #$reqId was successfully $decision.',
            )),
            backgroundColor: isApprove ? AppColors.greenOk : AppColors.charcoal,
          ),
        );
        _fetchRequests();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(data['error'] ?? 'Hindi na-update ang desisyon.'), backgroundColor: AppColors.redDanger),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(globalLanguage.isTagalog
              ? 'Hindi ma-update ang desisyon sa ngayon. Pakisubukan muli.'
              : 'Unable to update decision. Please try again.'),
          backgroundColor: AppColors.redDanger,
        ),
      );
    }
  }

  void _openRequisitionSlip(Map<String, dynamic> req) {
    final status = (req['status'] ?? 'pending').toString().toUpperCase();
    final qrToken = req['qr_token']?.toString() ?? '';
    final items = (req['items'] as List<dynamic>?) ?? [];
    final reqId = int.tryParse('${req['id']}') ?? 0;
    final isOwner = '${req['requester_id']}' == '${widget.user['id']}';
    final isSupervisor = widget.user['role'] == 'field_supervisor' || widget.user['role'] == 'admin';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 36,
                  height: 4,
                  decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
                ),
              ),
              const SizedBox(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Requisition Slip #${req['id']}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink)),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: _getStatusBg(status),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: _getStatusBorder(status)),
                    ),
                    child: Text(status, style: TextStyle(color: _getStatusColor(status), fontWeight: FontWeight.bold, fontSize: 11)),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text('Date: ${req['created_at'] ?? 'Recently'}', style: const TextStyle(fontSize: 11, color: AppColors.inkSoft)),
              const Divider(height: 18, color: AppColors.line),

              // Assigned truck & requester
              Row(
                children: [
                  const Icon(Icons.local_shipping_outlined, size: 18, color: AppColors.amber),
                  const SizedBox(width: 8),
                  Text('Fleet: ${req['plate_number'] ?? req['truck_plate_snapshot'] ?? 'No Truck'}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink)),
                  if (req['truck_model'] != null) Text(' (${req['truck_model']})', style: const TextStyle(color: AppColors.inkSoft, fontSize: 12)),
                ],
              ),
              const SizedBox(height: 4),
              if (req['requester_name'] != null)
                Text('Requester: ${req['requester_name']}', style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
              Text('Purpose: ${req['purpose'] ?? 'General Requisition'}', style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
              const SizedBox(height: 12),

              // Supervisor Note if present
              if (req['decision_note'] != null && req['decision_note'].toString().trim().isNotEmpty) ...[
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceSubtle,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.comment_outlined, size: 16, color: AppColors.amber),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Supervisor Note: ${req['decision_note']}',
                          style: const TextStyle(fontSize: 12, color: AppColors.ink, fontStyle: FontStyle.italic),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
              ],

              // Partial Release Notice if applicable
              if (req['is_partial_release'] == 1 || req['is_partial_release'] == true || req['is_partial_release'] == '1') ...[
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppColors.amberTint,
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: AppColors.amberBorder),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.warning_amber_rounded, size: 18, color: AppColors.amber),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              globalLanguage.isTagalog ? 'Bahagyang Release Lamang' : 'Partial Release Notice',
                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.amber),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              '${globalLanguage.isTagalog ? "Dahilan" : "Reason"}: ${req['partial_release_reason'] ?? "May mga gamit na hindi nakuha sa bodega."}',
                              style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
              ],

              // Items table
              const Text('Mga Nirequest na Gamit / Piyesa:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.ink)),
              const SizedBox(height: 8),
              Container(
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.line),
                  borderRadius: BorderRadius.circular(7),
                ),
                child: Column(
                  children: [
                    ...items.map((it) {
                      final isReleased = it['is_released'] == 1 || it['is_released'] == true || it['is_released'] == '1';
                      final isUnreleasedInReleasedReq = status == 'RELEASED' && !isReleased;

                      return Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        child: Row(
                          children: [
                            AppItemImage(
                              imageUrl: AppConfig.resolveImageUrl(
                                it['image_url'],
                                filename: it['image_filename'],
                                activeBaseUrl: _baseUrl,
                              ),
                              width: 34,
                              height: 34,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(it['item_name'] ?? 'Item', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: AppColors.ink)),
                                  if (it['variant_selected'] != null && it['variant_selected'].toString().isNotEmpty)
                                    Text('Variant: ${it['variant_selected']}', style: const TextStyle(fontSize: 10, color: AppColors.inkSoft)),
                                  if (isUnreleasedInReleasedReq)
                                    Text(
                                      it['release_note'] != null && it['release_note'].toString().isNotEmpty
                                          ? 'Dahilan: ${it['release_note']}'
                                          : (globalLanguage.isTagalog ? 'Hindi naibigay / kulang' : 'Not fulfilled / missing'),
                                      style: const TextStyle(fontSize: 10, color: AppColors.redDanger, fontStyle: FontStyle.italic),
                                    ),
                                ],
                              ),
                            ),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text('${it['quantity_requested']} ${it['unit'] ?? 'pcs'}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.ink)),
                                if (status == 'RELEASED') ...[
                                  const SizedBox(height: 2),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                    decoration: BoxDecoration(
                                      color: isReleased ? AppColors.greenTint : AppColors.redTint,
                                      borderRadius: BorderRadius.circular(4),
                                    ),
                                    child: Text(
                                      isReleased
                                          ? (globalLanguage.isTagalog ? 'Nai-release' : 'Released')
                                          : (globalLanguage.isTagalog ? 'Kulang' : 'Unfulfilled'),
                                      style: TextStyle(
                                        fontSize: 9.5,
                                        fontWeight: FontWeight.bold,
                                        color: isReleased ? AppColors.greenOk : AppColors.redDanger,
                                      ),
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ],
                        ),
                      );
                    }),
                  ],
                ),
              ),

              const SizedBox(height: 18),

              // SCANNABLE DYNAMIC LIVE QR CODE DISPLAY (Only show QR when APPROVED for warehouse pickup!)
              if (status == 'APPROVED' && qrToken.isNotEmpty) ...[
                DynamicPickupQrWidget(
                  qrToken: qrToken,
                  userId: widget.user['id'],
                ),
                const SizedBox(height: 14),
              ] else if (status == 'PENDING') ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.amberTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.amber.withOpacity(0.3)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.hourglass_top_rounded, color: AppColors.amber, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.isTagalog
                              ? 'Naghihintay ng pag-apruba ng Field Supervisor bago mai-release ang pickup QR code.'
                              : 'Awaiting Field Supervisor approval before pickup QR code is issued.',
                          style: const TextStyle(fontSize: 12, color: AppColors.ink, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ] else if (status == 'RELEASED') ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.greenTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.greenOk.withOpacity(0.3)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle_rounded, color: AppColors.greenOk, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.isTagalog
                              ? 'Nai-release na sa bodega ang mga kagamitan sa kahilingang ito.'
                              : 'All approved items in this requisition have been released at the warehouse.',
                          style: const TextStyle(fontSize: 12, color: AppColors.ink, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ] else if (status == 'REJECTED' || status == 'DECLINED') ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.redTint,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.redDanger.withOpacity(0.3)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.cancel_outlined, color: AppColors.redDanger, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.isTagalog
                              ? 'Tinanggihan ang kahilingang ito ng Supervisor.'
                              : 'This requisition has been declined by the Supervisor.',
                          style: const TextStyle(fontSize: 12, color: AppColors.ink, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ] else if (status == 'CANCELLED') ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceSubtle,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.line),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.remove_circle_outline, color: AppColors.inkSoft, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          globalLanguage.isTagalog
                              ? 'Kinansela ang kahilingang ito.'
                              : 'This requisition was cancelled.',
                          style: const TextStyle(fontSize: 12, color: AppColors.inkSoft, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ],

              // ACTION BUTTONS (Full Parity with Web requisition/view.php!)
              // 1. Cancel button for requester if still pending
              if (status == 'PENDING' && isOwner) ...[
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.redDanger,
                    side: const BorderSide(color: AppColors.redBorder),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  icon: const Icon(Icons.cancel_outlined, size: 16),
                  label: Text(globalLanguage.isTagalog ? 'Kanselahin ang Requisition' : 'Cancel Requisition'),
                  onPressed: () => _cancelRequisition(reqId),
                ),
                const SizedBox(height: 10),
              ],

              // 2. Supervisor Approval & Decline buttons (SoD: cannot approve own request)
              if (status == 'PENDING' && isSupervisor && !isOwner) ...[
                Row(
                  children: [
                    Expanded(
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.greenOk,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 12),
                        ),
                        icon: const Icon(Icons.check_circle_outline, size: 16),
                        label: Text(globalLanguage.t('approve')),
                        onPressed: () => _decideRequisition(reqId, 'approved'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppColors.redDanger,
                          side: const BorderSide(color: AppColors.redBorder),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                        ),
                        icon: const Icon(Icons.highlight_off, size: 16),
                        label: Text(globalLanguage.t('reject')),
                        onPressed: () => _decideRequisition(reqId, 'declined'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
              ],

              ElevatedButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(globalLanguage.t('close')),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _openLoanSlip(Map<String, dynamic> loan) {
    final status = (loan['status'] ?? 'borrowed').toString().toUpperCase();
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                AppItemImage(
                  imageUrl: AppConfig.resolveImageUrl(
                    loan['image_url'],
                    filename: loan['image_filename'],
                    activeBaseUrl: _baseUrl,
                  ),
                  width: 54,
                  height: 54,
                  borderRadius: BorderRadius.circular(8),
                  fallbackIcon: Icons.handyman_outlined,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              loan['item_name'] ?? 'Equipment Loan',
                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.ink),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: _getStatusBg(status),
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: _getStatusBorder(status)),
                            ),
                            child: Text(status, style: TextStyle(color: _getStatusColor(status), fontWeight: FontWeight.bold, fontSize: 11)),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text('Item Code: ${loan['item_code']} • Qty: ${loan['quantity']} ${loan['unit'] ?? 'pc'}', style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 20, color: AppColors.line),

            Row(
              children: [
                const Icon(Icons.calendar_today_outlined, size: 16, color: AppColors.amber),
                const SizedBox(width: 8),
                Text('Borrowed: ${loan['borrowed_at'] ?? 'N/A'}', style: const TextStyle(fontSize: 12, color: AppColors.ink)),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                const Icon(Icons.event_busy_outlined, size: 16, color: AppColors.redDanger),
                const SizedBox(width: 8),
                Text('Due Date: ${loan['due_date'] ?? 'N/A'}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.ink)),
                const SizedBox(width: 8),
                if (loan['days_left'] != null && loan['returned_at'] == null)
                  Text(
                    loan['days_left'] < 0
                        ? globalLanguage.choice('(${loan['days_left'].abs()} araw lampas)', '(${loan['days_left'].abs()} days overdue)')
                        : globalLanguage.choice('(${loan['days_left']} araw natitira)', '(${loan['days_left']} days remaining)'),
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: loan['days_left'] < 0 ? AppColors.redDanger : AppColors.amber),
                  ),
              ],
            ),
            if (loan['extension_status'] == 'pending') ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppColors.amberTint,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: AppColors.amberBorder),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.hourglass_top_rounded, size: 15, color: AppColors.amber),
                        const SizedBox(width: 6),
                        Text(
                          globalLanguage.choice('Naghihintay ng Extension (+${loan['extension_days']} araw)', 'Extension Pending (+${loan['extension_days']} days)'),
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.amber),
                        ),
                      ],
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${globalLanguage.choice("Dahilan", "Reason")}: "${loan['extension_reason'] ?? (globalLanguage.choice("Walang nakasaad na dahilan", "No reason provided"))}"',
                      style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: AppColors.inkSoft),
                    ),
                  ],
                ),
              ),
            ] else if (loan['extension_status'] == 'approved' && (int.tryParse('${loan['extension_days']}') ?? 0) > 0) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.blueTint,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: AppColors.blueBorder),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.check_circle_outline, size: 15, color: AppColors.blueInfo),
                    const SizedBox(width: 6),
                    Text(
                      globalLanguage.choice('Inaprubahang Extended (+${loan['extension_days']} araw)', 'Extension Approved (+${loan['extension_days']} days)'),
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.blueInfo),
                    ),
                  ],
                ),
              ),
            ] else if (loan['extension_status'] == 'declined') ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppColors.redTint,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: AppColors.redBorder),
                ),
                child: Text(
                  'Extension Declined: ${loan['extension_decision_note'] ?? 'Denied by Inventory Staff'}',
                  style: const TextStyle(fontSize: 11, color: AppColors.redDanger),
                ),
              ),
            ],
            if (loan['returned_at'] != null) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  const Icon(Icons.check_circle_outline, size: 16, color: AppColors.greenOk),
                  const SizedBox(width: 8),
                  Text('Returned: ${loan['returned_at']}', style: const TextStyle(fontSize: 12, color: AppColors.greenOk, fontWeight: FontWeight.bold)),
                ],
              ),
            ],
            const SizedBox(height: 10),
            Row(
              children: [
                const Icon(Icons.local_shipping_outlined, size: 16, color: AppColors.amber),
                const SizedBox(width: 8),
                Text('Fleet: ${loan['plate_number'] ?? loan['truck_plate_snapshot'] ?? 'No vehicle recorded'}', style: const TextStyle(fontSize: 12, color: AppColors.ink)),
              ],
            ),
            const SizedBox(height: 6),
            Text('Purpose: ${loan['requisition_purpose'] ?? 'Equipment Loan'}', style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
            const SizedBox(height: 16),
            if (loan['returned_at'] == null && loan['extension_status'] != 'pending') ...[
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.amber,
                  side: const BorderSide(color: AppColors.amber),
                  padding: const EdgeInsets.symmetric(vertical: 11),
                ),
                icon: const Icon(Icons.more_time_rounded, size: 18),
                label: Text(loan['extension_status'] == 'approved'
                    ? globalLanguage.choice('Humiling Pa ng Karagdagang Araw', 'Request Further Extension')
                    : globalLanguage.choice('Humiling ng Extension (Nasa Biyahe)', 'Request Extension (On Trip)')),
                onPressed: () {
                  Navigator.pop(ctx);
                  _showLoanExtensionDialog(loan);
                },
              ),
              const SizedBox(height: 10),
            ],
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(globalLanguage.t('close')),
            ),
          ],
        ),
      ),
    );
  }

  void _showLoanExtensionDialog(Map<String, dynamic> loan) {
    int selectedDays = 3;
    final reasonCtrl = TextEditingController();
    bool isSubmitting = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setSheetState) => Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
          child: Container(
            decoration: const BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
            ),
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 36,
                    height: 4,
                    decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
                  ),
                ),
                const SizedBox(height: 14),
                Row(
                  children: [
                    const Icon(Icons.more_time_rounded, color: AppColors.amber, size: 22),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Tool Extension: ${loan['item_name']}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.ink),
                      ),
                    ),
                  ],
                ),
                const Divider(height: 20, color: AppColors.line),
                Text(
                  globalLanguage.choice('Karagdagang Araw:', 'Additional Days:'),
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.ink),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [1, 2, 3, 5, 7, 10].map((d) {
                    final isSel = selectedDays == d;
                    return ChoiceChip(
                      label: Text('+$d ${globalLanguage.choice("araw", "days")}'),
                      selected: isSel,
                      selectedColor: AppColors.amber,
                      labelStyle: TextStyle(
                        color: isSel ? Colors.white : AppColors.ink,
                        fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                        fontSize: 12,
                      ),
                      onSelected: (val) {
                        if (val) setSheetState(() => selectedDays = d);
                      },
                    );
                  }).toList(),
                ),
                Text(
                  globalLanguage.isTagalog ? 'Dahilan ng Pagka-delay (Pindutin o mag-type):' : 'Reason for Delay (Tap or type):',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppColors.ink),
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 5,
                  children: [
                    globalLanguage.isTagalog ? 'Na-delay ang biyahe pabalik ng planta' : 'Delayed return trip to plant',
                    globalLanguage.isTagalog ? 'Masamang panahon / Baha o bagyo' : 'Severe weather / Storm / Flood',
                    globalLanguage.isTagalog ? 'Nasiraan ang truck / Emergency repair' : 'Truck breakdown / Roadside repair',
                    globalLanguage.isTagalog ? 'Hindi pa tapos sa project site' : 'Unfinished work at project site',
                    globalLanguage.isTagalog ? 'Iba pa (Custom)' : 'Other (Custom)',
                  ].map((r) {
                    final isCustom = r.startsWith('Iba pa') || r.startsWith('Other');
                    final isSelected = isCustom
                        ? (reasonCtrl.text.isNotEmpty && ![
                            'Na-delay ang biyahe pabalik ng planta',
                            'Delayed return trip to plant',
                            'Masamang panahon / Baha o bagyo',
                            'Severe weather / Storm / Flood',
                            'Nasiraan ang truck / Emergency repair',
                            'Truck breakdown / Roadside repair',
                            'Hindi pa tapos sa project site',
                            'Unfinished work at project site',
                          ].contains(reasonCtrl.text))
                        : reasonCtrl.text == r;
                    return InkWell(
                      onTap: () {
                        setSheetState(() {
                          if (isCustom) {
                            reasonCtrl.text = '';
                          } else {
                            reasonCtrl.text = r;
                          }
                        });
                      },
                      borderRadius: BorderRadius.circular(16),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: isSelected ? AppColors.amberTint : AppColors.surfaceSubtle,
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(color: isSelected ? AppColors.amber : AppColors.line),
                        ),
                        child: Text(
                          r,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                            color: isSelected ? AppColors.amber : AppColors.inkSoft,
                          ),
                        ),
                      ),
                    );
                  }).toList(),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: reasonCtrl,
                  maxLines: 2,
                  decoration: InputDecoration(
                    hintText: globalLanguage.isTagalog
                        ? 'Pumili sa itaas o mag-type ng sariling dahilan...'
                        : 'Select above or type reason...',
                    hintStyle: const TextStyle(fontSize: 12, color: AppColors.inkLight),
                    contentPadding: const EdgeInsets.all(10),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                  ),
                ),
                const SizedBox(height: 16),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.amber,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          final reason = reasonCtrl.text.trim();
                          if (reason.isEmpty) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text(globalLanguage.choice('Paki-lagay ang dahilan ng pagka-delay.', 'Please enter the delay reason.'))),
                            );
                            return;
                          }
                          final messenger = ScaffoldMessenger.of(context);
                          setSheetState(() => isSubmitting = true);
                          try {
                            final baseUrl = await AppConfig.getBaseUrl();
                            final url = Uri.parse('$baseUrl/loans.php');
                            final res = await http.post(
                              url,
                              headers: AppConfig.authHeaders(widget.user),
                              body: jsonEncode({
                                'action': 'request_extension',
                                'loan_id': loan['id'],
                                'days': selectedDays,
                                'reason': reason,
                                'user_id': widget.user['id'],
                                'token': widget.user['token'] ?? '',
                              }),
                            );
                            final data = jsonDecode(res.body);
                            if (res.statusCode == 200 && data['success'] == true) {
                              if (ctx.mounted) Navigator.pop(ctx);
                              if (mounted) {
                                messenger.showSnackBar(
                                  SnackBar(
                                    backgroundColor: AppColors.greenOk,
                                    content: Text('Naisumite na ang hiling na +$selectedDays araw sa Inventory Staff.'),
                                  ),
                                );
                                _fetchLoans();
                              }
                            } else {
                              if (mounted) {
                                messenger.showSnackBar(
                                  SnackBar(
                                    backgroundColor: AppColors.redDanger,
                                    content: Text(data['error'] ?? 'Hindi naisumite ang request.'),
                                  ),
                                );
                              }
                            }
                          } catch (_) {
                            if (mounted) {
                              messenger.showSnackBar(
                                SnackBar(
                                  backgroundColor: AppColors.redDanger,
                                  content: Text(globalLanguage.isTagalog
                                      ? 'Hindi maipasa ang request sa ngayon. Pakisubukan muli.'
                                      : 'Unable to submit request. Please try again.'),
                                ),
                              );
                            }
                          } finally {
                            if (mounted) setSheetState(() => isSubmitting = false);
                          }
                        },
                  child: isSubmitting
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Text('Isumite sa Inventory Staff', style: TextStyle(fontWeight: FontWeight.bold)),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isSupervisor = widget.user['role'] == 'field_supervisor' || widget.user['role'] == 'admin';
    final filterTabs = isSupervisor
        ? ['All', 'Approvals', 'Pending', 'Approved', 'Released']
        : ['All', 'Pending', 'Approved', 'Released'];

    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: _subSection == 0 ? globalLanguage.t('req_title') : globalLanguage.t('loan_title'),
        user: widget.user,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: () {
              if (_subSection == 0) {
                _fetchRequests();
              } else {
                _fetchLoans();
              }
            },
            tooltip: globalLanguage.t('refresh'),
          ),
        ],
      ),
      body: Column(
        children: [
          // Sub-Section Switcher (Requisitions vs Borrowed Tools Loans)
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: SegmentedButton<int>(
                    style: ButtonStyle(
                      backgroundColor: WidgetStateProperty.resolveWith((states) {
                        if (states.contains(WidgetState.selected)) return AppColors.surfaceSubtle;
                        return Colors.white;
                      }),
                    ),
                    segments: [
                      ButtonSegment<int>(
                        value: 0,
                        icon: const Icon(Icons.assignment_outlined, size: 16),
                        label: Text(
                          globalLanguage.isTagalog ? 'Requisition (${_requests.length})' : 'Requisitions (${_requests.length})',
                          style: const TextStyle(fontSize: 12),
                        ),
                      ),
                      ButtonSegment<int>(
                        value: 1,
                        icon: const Icon(Icons.handyman_outlined, size: 16),
                        label: Text(
                          globalLanguage.isTagalog ? 'Hiram na Gamit (${_loans.length})' : 'Borrowed Tools (${_loans.length})',
                          style: const TextStyle(fontSize: 12),
                        ),
                      ),
                    ],
                    selected: {_subSection},
                    onSelectionChanged: (set) {
                      setState(() => _subSection = set.first);
                      if (_subSection == 1 && _loans.isEmpty) _fetchLoans();
                    },
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1, color: AppColors.line),

          // SECTION 0: REQUISITIONS
          if (_subSection == 0) ...[
            Container(
              color: AppColors.surface,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: filterTabs.map((tab) {
                    final isSelected = tab == _filterTab;
                    final isApprovalsTab = tab == 'Approvals';
                    String tabLabel = tab;
                    if (globalLanguage.isTagalog) {
                      if (tab == 'All') {
                        tabLabel = 'Lahat';
                      } else if (tab == 'Approvals') {
                        tabLabel = 'Aprubasyon (Team)';
                      } else if (tab == 'Pending') {
                        tabLabel = 'Naghihintay';
                      } else if (tab == 'Approved') {
                        tabLabel = 'Aprubado';
                      } else if (tab == 'Released') {
                        tabLabel = 'Nai-release';
                      }
                    } else if (tab == 'Approvals') {
                      tabLabel = 'Pending Approvals (Team)';
                    }
                    return Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        avatar: isApprovalsTab ? const Icon(Icons.verified_user_outlined, size: 14, color: AppColors.amber) : null,
                        label: Text(tabLabel),
                        selected: isSelected,
                        selectedColor: AppColors.surfaceSubtle,
                        backgroundColor: AppColors.paper,
                        side: BorderSide(color: isSelected ? AppColors.amber : AppColors.line),
                        labelStyle: TextStyle(
                          color: isSelected ? AppColors.amber : AppColors.ink,
                          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                          fontSize: 12,
                        ),
                        onSelected: (val) {
                          if (val) {
                            setState(() => _filterTab = tab);
                            _fetchRequests();
                          }
                        },
                      ),
                    );
                  }).toList(),
                ),
              ),
            ),
            const Divider(height: 1, color: AppColors.line),

            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
                  : _filteredRequests.isEmpty
                      ? Center(
                          child: Text(
                            globalLanguage.isTagalog ? 'Walang requisitions sa kategoryang ito.' : 'No requisitions in this category.',
                            style: const TextStyle(color: AppColors.inkSoft),
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
                          itemCount: _filteredRequests.length,
                          itemBuilder: (ctx, idx) {
                            final req = _filteredRequests[idx];
                            final status = req['status']?.toString() ?? 'pending';
                            final color = _getStatusColor(status);
                            final priorityScore = req['priority_score'] ?? '0.00';
                            final items = (req['items'] as List<dynamic>?) ?? [];
                            final itemsSummary = items.isNotEmpty
                                ? items.map((i) => '${i['item_name']} (${i['quantity_requested']} ${i['unit'] ?? 'pcs'})').join(', ')
                                : (req['purpose'] ?? 'General Requisition');

                            return Card(
                              margin: const EdgeInsets.only(bottom: 10),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(10),
                                onTap: () => _openRequisitionSlip(req),
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text('Req #${req['id']}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.ink)),
                                          Row(
                                            children: [
                                              if (req['is_partial_release'] == 1 || req['is_partial_release'] == true || req['is_partial_release'] == '1') ...[
                                                Container(
                                                  margin: const EdgeInsets.only(right: 6),
                                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                  decoration: BoxDecoration(
                                                    color: AppColors.amberTint,
                                                    borderRadius: BorderRadius.circular(4),
                                                    border: Border.all(color: AppColors.amberBorder),
                                                  ),
                                                  child: Text(
                                                    globalLanguage.isTagalog ? 'BAHAGYA' : 'PARTIAL',
                                                    style: const TextStyle(color: AppColors.amber, fontSize: 9, fontWeight: FontWeight.bold),
                                                  ),
                                                ),
                                              ],
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                                                decoration: BoxDecoration(
                                                  color: _getStatusBg(status),
                                                  borderRadius: BorderRadius.circular(4),
                                                  border: Border.all(color: _getStatusBorder(status)),
                                                ),
                                                child: Text(status.toUpperCase(), style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.bold)),
                                              ),
                                            ],
                                          ),
                                        ],
                                      ),
                                      const Divider(height: 16, color: AppColors.line),
                                      Text(itemsSummary, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.ink)),
                                      const SizedBox(height: 6),
                                      Row(
                                        children: [
                                          const Icon(Icons.local_shipping_outlined, size: 15, color: AppColors.amber),
                                          const SizedBox(width: 4),
                                          Text(
                                            'Truck: ${req['plate_number'] ?? req['truck_plate_snapshot'] ?? 'N/A'}',
                                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: AppColors.ink),
                                          ),
                                          if (isSupervisor && _filterTab == 'Approvals') ...[
                                            const Spacer(),
                                            Text('Priority: $priorityScore', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.amber)),
                                          ],
                                        ],
                                      ),
                                      const SizedBox(height: 8),
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Text('Date: ${req['created_at'] ?? 'Recently'}', style: const TextStyle(fontSize: 11, color: AppColors.inkSoft)),
                                          const Text('QR Slip →', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.amber)),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ],

          // SECTION 1: BORROWED TOOLS (LOANS - Parity with Web requisition/my_loans.php)
          if (_subSection == 1) ...[
            Expanded(
              child: _isLoadingLoans
                  ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
                  : _loans.isEmpty
                      ? const Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.handyman_outlined, size: 48, color: AppColors.inkLight),
                              SizedBox(height: 12),
                              Text('Wala kang hiniram na gamit sa kasalukuyan.', style: TextStyle(color: AppColors.inkSoft, fontSize: 14)),
                            ],
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
                          itemCount: _loans.length,
                          itemBuilder: (ctx, idx) {
                            final loan = _loans[idx];
                            final status = (loan['status'] ?? 'borrowed').toString();
                            final extStatus = (loan['extension_status'] ?? 'none').toString();
                            final extDays = loan['extension_days'] != null ? (int.tryParse(loan['extension_days'].toString()) ?? 0) : 0;
                            final isPendingExt = extStatus == 'pending' || status == 'extension_pending';
                            final isExtended = extStatus == 'approved' && extDays > 0;
                            final isOverdue = status == 'overdue';
                            final isReturned = status == 'returned';
                            final daysLeft = loan['days_left'];

                            return Card(
                              margin: const EdgeInsets.only(bottom: 10),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(10),
                                onTap: () => _openLoanSlip(loan),
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Row(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      AppItemImage(
                                        imageUrl: AppConfig.resolveImageUrl(
                                          loan['image_url'],
                                          filename: loan['image_filename'],
                                          activeBaseUrl: _baseUrl,
                                        ),
                                        width: 44,
                                        height: 44,
                                        borderRadius: BorderRadius.circular(8),
                                        fallbackIcon: Icons.handyman_outlined,
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Row(
                                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                              children: [
                                                Expanded(
                                                  child: Text(
                                                    loan['item_name'] ?? 'Equipment',
                                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.ink),
                                                  ),
                                                ),
                                                Container(
                                                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                                                  decoration: BoxDecoration(
                                                    color: isPendingExt
                                                        ? AppColors.amberTint
                                                        : (isExtended
                                                            ? AppColors.blueTint
                                                            : (isOverdue ? AppColors.redTint : (isReturned ? AppColors.greenTint : AppColors.amberTint))),
                                                    borderRadius: BorderRadius.circular(4),
                                                    border: Border.all(
                                                      color: isPendingExt
                                                          ? AppColors.amberBorder
                                                          : (isExtended
                                                              ? AppColors.blueBorder
                                                              : (isOverdue ? AppColors.redBorder : (isReturned ? AppColors.greenBorder : AppColors.amberBorder))),
                                                    ),
                                                  ),
                                                  child: Text(
                                                    isPendingExt
                                                        ? 'EXT. PENDING (+${extDays}D)'
                                                        : (isExtended
                                                            ? 'EXTENDED (+${extDays}D)'
                                                            : status.toUpperCase()),
                                                    style: TextStyle(
                                                      color: isPendingExt
                                                          ? AppColors.amber
                                                          : (isExtended
                                                              ? AppColors.blueInfo
                                                              : (isOverdue ? AppColors.redDanger : (isReturned ? AppColors.greenOk : AppColors.amber))),
                                                      fontSize: 10,
                                                      fontWeight: FontWeight.bold,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                            ),
                                            const SizedBox(height: 4),
                                            Text(
                                              'Code: ${loan['item_code']} • Qty: ${loan['quantity']} ${loan['unit'] ?? 'pc'}',
                                              style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                                            ),
                                            const Divider(height: 16, color: AppColors.line),
                                            Row(
                                              children: [
                                                const Icon(Icons.event_outlined, size: 14, color: AppColors.amber),
                                                const SizedBox(width: 4),
                                                Text('Due: ${loan['due_date'] ?? 'N/A'}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.ink)),
                                                const Spacer(),
                                                if (daysLeft != null && !isReturned)
                                                  Text(
                                                    daysLeft < 0 ? '${daysLeft.abs()} days late' : '$daysLeft days left',
                                                    style: TextStyle(
                                                      fontSize: 11,
                                                      fontWeight: FontWeight.bold,
                                                      color: daysLeft < 0 ? AppColors.redDanger : AppColors.amber,
                                                    ),
                                                  ),
                                              ],
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------
// Special PO Request Sheet Helper (Web Parity)
// ---------------------------------------------------------
void showNewItemRequestSheet(
  BuildContext context,
  Map<String, dynamic> user, {
  String? initialItemName,
  String? initialReason,
  VoidCallback? onSuccess,
}) {
  final nameCtrl = TextEditingController(text: initialItemName ?? '');
  final qtyCtrl = TextEditingController(text: '1');
  final reasonCtrl = TextEditingController(text: initialReason ?? '');
  String selectedUnit = 'pc';
  const unitOptions = ['pc', 'set', 'liter', 'box', 'roll', 'pair'];
  bool isSubmitting = false;

  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setModalState) => Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
        ),
        padding: EdgeInsets.fromLTRB(20, 16, 20, MediaQuery.of(ctx).viewInsets.bottom + 24),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 36,
                  height: 4,
                  decoration: BoxDecoration(color: AppColors.lineStrong, borderRadius: BorderRadius.circular(2)),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                globalLanguage.choice('Mag-request ng Bagong Gamit (PO)', 'Request New Item (Special PO)'),
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: AppColors.ink),
              ),
              const SizedBox(height: 14),
              Text(
                globalLanguage.choice('Pangalan ng Gamit / Deskripsyon', 'Item Name / Description'),
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
              ),
              const SizedBox(height: 6),
              TextField(
                controller: nameCtrl,
                decoration: InputDecoration(
                  hintText: globalLanguage.choice('Hal. Brake Fluid DOT4, Air Filter HD', 'E.g. Brake Fluid DOT4, Air Filter HD'),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                ),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    flex: 3,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          globalLanguage.choice('Tinatayang Dami', 'Estimated Quantity'),
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
                        ),
                        const SizedBox(height: 6),
                        TextField(
                          controller: qtyCtrl,
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(
                            hintText: globalLanguage.choice('Hal. 2', 'E.g. 2'),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 2,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          globalLanguage.choice('Yunit', 'Unit'),
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
                        ),
                        const SizedBox(height: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10),
                          decoration: BoxDecoration(
                            border: Border.all(color: AppColors.line),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: DropdownButtonHideUnderline(
                            child: DropdownButton<String>(
                              isExpanded: true,
                              value: selectedUnit,
                              items: unitOptions.map((u) => DropdownMenuItem(value: u, child: Text(u, style: const TextStyle(fontSize: 13)))).toList(),
                              onChanged: (val) {
                                if (val != null) {
                                  setModalState(() => selectedUnit = val);
                                }
                              },
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                globalLanguage.isTagalog ? 'Dahilan ng Request (Pindutin o mag-type):' : 'Reason / Justification (Tap or type):',
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.ink),
              ),
              const SizedBox(height: 6),
              Wrap(
                spacing: 6,
                runSpacing: 5,
                children: [
                  globalLanguage.isTagalog ? 'Wala sa bodega (Out of stock)' : 'Out of stock in warehouse',
                  globalLanguage.isTagalog ? 'Emergency pyesa ng truck' : 'Emergency truck replacement part',
                  globalLanguage.isTagalog ? 'Espesyal na tool para sa biyahe' : 'Specialized tool for trip',
                  globalLanguage.isTagalog ? 'Ubos na ang consumable' : 'Consumable replenishment',
                  globalLanguage.isTagalog ? 'Iba pa (Custom)' : 'Other (Custom)',
                ].map((r) {
                  final isCustom = r.startsWith('Iba pa') || r.startsWith('Other');
                  final isSelected = isCustom
                      ? (reasonCtrl.text.isNotEmpty && ![
                          'Wala sa bodega (Out of stock)',
                          'Out of stock in warehouse',
                          'Emergency pyesa ng truck',
                          'Emergency truck replacement part',
                          'Espesyal na tool para sa biyahe',
                          'Specialized tool for trip',
                          'Ubos na ang consumable',
                          'Consumable replenishment',
                        ].contains(reasonCtrl.text))
                      : reasonCtrl.text == r;
                  return InkWell(
                    onTap: () {
                      setModalState(() {
                        if (isCustom) {
                          reasonCtrl.text = '';
                        } else {
                          reasonCtrl.text = r;
                        }
                      });
                    },
                    borderRadius: BorderRadius.circular(16),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: isSelected ? AppColors.amberTint : AppColors.surfaceSubtle,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: isSelected ? AppColors.amber : AppColors.line),
                      ),
                      child: Text(
                        r,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                          color: isSelected ? AppColors.amber : AppColors.inkSoft,
                        ),
                      ),
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: reasonCtrl,
                maxLines: 2,
                decoration: InputDecoration(
                  hintText: globalLanguage.isTagalog
                      ? 'Pumili sa itaas o mag-type ng dahilan...'
                      : 'Select above or type custom reason...',
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: AppColors.line)),
                  contentPadding: const EdgeInsets.all(10),
                ),
              ),
              const SizedBox(height: 18),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.amber,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 13),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
                ),
                onPressed: isSubmitting
                    ? null
                    : () async {
                        final name = nameCtrl.text.trim();
                        if (name.isEmpty) return;
                        setModalState(() => isSubmitting = true);
                        try {
                          final baseUrl = await AppConfig.getBaseUrl();
                          final url = Uri.parse('$baseUrl/item_requests.php');
                          final res = await http.post(
                            url,
                            headers: AppConfig.authHeaders(user),
                            body: jsonEncode({
                              'user_id': user['id'],
                              'token': user['token'] ?? '',
                              'item_name': name,
                              'quantity': int.tryParse(qtyCtrl.text.trim()) ?? 1,
                              'unit': selectedUnit,
                              'reason': reasonCtrl.text.trim(),
                            }),
                          ).timeout(const Duration(seconds: 8));

                          final data = jsonDecode(res.body);
                          if (res.statusCode == 200 && data['success'] == true) {
                            if (ctx.mounted) Navigator.pop(ctx);
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  backgroundColor: AppColors.greenOk,
                                  content: Text(globalLanguage.choice(
                                    'Naisumite na ang Special Purchase Request (PO).',
                                    'Special Purchase Request (PO) has been submitted.',
                                  )),
                                ),
                              );
                            }
                            onSuccess?.call();
                          } else {
                            final errMsg = data['error'] ?? data['message'] ?? 'Hindi maipasa ang PO request.';
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  backgroundColor: AppColors.redDanger,
                                  content: Text(errMsg.toString()),
                                ),
                              );
                            }
                          }
                        } catch (_) {
                          if (context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                backgroundColor: AppColors.redDanger,
                                content: Text(globalLanguage.isTagalog
                                    ? 'Hindi maipasa ang PO request sa ngayon. Pakisubukan muli.'
                                    : 'Unable to submit PO request. Please try again.'),
                              ),
                            );
                          }
                        } finally {
                          setModalState(() => isSubmitting = false);
                        }
                      },
                child: isSubmitting
                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : Text(
                        globalLanguage.isTagalog ? 'I-submit ang PO Request' : 'Submit PO Request',
                        style: const TextStyle(fontWeight: FontWeight.bold),
                      ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

// ---------------------------------------------------------
// Tab 3: Special PO Request Screen
// ---------------------------------------------------------
class SpecialPoScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  const SpecialPoScreen({super.key, required this.user});

  @override
  State<SpecialPoScreen> createState() => _SpecialPoScreenState();
}

class _SpecialPoScreenState extends State<SpecialPoScreen> {
  List<dynamic> _poList = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchPORequests();
  }

  Future<void> _fetchPORequests() async {
    setState(() => _isLoading = true);
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/item_requests.php?user_id=${widget.user['id']}&role=${widget.user['role']}&token=${widget.user['token'] ?? ''}');
      final res = await http.get(url, headers: AppConfig.authHeaders(widget.user, isJson: false)).timeout(const Duration(seconds: 20));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final payload = data['data'] ?? data;
        if (mounted) {
          setState(() => _poList = payload is List ? payload : []);
        }
      }
    } catch (_) {}
    if (mounted) setState(() => _isLoading = false);
  }

  void _openNewPOSheet() {
    showNewItemRequestSheet(context, widget.user, onSuccess: _fetchPORequests);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.t('nav_special_po'),
        user: widget.user,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _fetchPORequests,
            tooltip: globalLanguage.t('refresh'),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.amber,
        foregroundColor: Colors.white,
        elevation: 3,
        onPressed: _openNewPOSheet,
        icon: const Icon(Icons.add),
        label: Text(globalLanguage.isTagalog ? 'Bagong PO Request' : 'New PO Request'),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.amber))
          : _poList.isEmpty
              ? Center(
                  child: Text(
                    globalLanguage.isTagalog ? 'Walang pending Special PO request.' : 'No pending Special PO requests.',
                    style: const TextStyle(color: AppColors.inkSoft),
                  ),
                )
              : ListView.builder(
                  padding: const EdgeInsets.fromLTRB(14, 14, 14, 84),
                  itemCount: _poList.length,
                  itemBuilder: (ctx, idx) {
                    final po = _poList[idx];
                    return Card(
                      margin: const EdgeInsets.only(bottom: 10),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Text(po['item_name'] ?? 'PO Item', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.ink)),
                                Text('Qty: ${po['quantity'] ?? 1}', style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.amber)),
                              ],
                            ),
                            const SizedBox(height: 4),
                            Text(po['reason'] ?? (globalLanguage.isTagalog ? 'Walang nakasaad na dahilan' : 'No reason stated'), style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
                            const SizedBox(height: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                              decoration: BoxDecoration(
                                color: AppColors.amberTint,
                                borderRadius: BorderRadius.circular(4),
                                border: Border.all(color: AppColors.amberBorder),
                              ),
                              child: Text(
                                (po['status'] ?? 'pending').toString().toUpperCase(),
                                style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.amber),
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
    );
  }
}

// ---------------------------------------------------------
// Tab 4: Profile, Offline Sync & Settings Screen
// ---------------------------------------------------------
class ProfileScreen extends StatefulWidget {
  final Map<String, dynamic> user;
  final VoidCallback onQueueChanged;
  const ProfileScreen({super.key, required this.user, required this.onQueueChanged});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  List<dynamic> _offlineQueue = [];
  bool _isSyncing = false;
  String _currentServerUrl = '';

  @override
  void initState() {
    super.initState();
    _loadState();
  }

  Future<void> _loadState() async {
    final queue = await AppConfig.getOfflineQueue();
    final url = await AppConfig.getBaseUrl();
    if (mounted) {
      setState(() {
        _offlineQueue = queue;
        _currentServerUrl = url;
      });
    }
  }

  Future<void> _syncQueue() async {
    if (_offlineQueue.isEmpty) return;

    setState(() => _isSyncing = true);

    int successCount = 0;
    try {
      final baseUrl = await AppConfig.getBaseUrl();
      final url = Uri.parse('$baseUrl/requisitions.php');

      final remaining = <dynamic>[];
      for (var req in _offlineQueue) {
        try {
          final res = await http.post(
            url,
            headers: AppConfig.authHeaders(widget.user),
            body: jsonEncode(req),
          ).timeout(const Duration(seconds: 8));

          final data = jsonDecode(res.body);
          if (res.statusCode == 200 && data['success'] == true) {
            successCount++;
          } else {
            remaining.add(req);
          }
        } catch (_) {
          remaining.add(req);
        }
      }

      await AppConfig.saveOfflineQueue(remaining);
      await _loadState();
      widget.onQueueChanged();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Matagumpay na nai-sync ang $successCount offline requisition(s)!${remaining.isNotEmpty ? " (${remaining.length} ang nanatili sa queue)" : ""}'),
            backgroundColor: remaining.isEmpty ? AppColors.greenOk : AppColors.amber,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isSyncing = false);
    }
  }

  void _openServerSettings() {
    final ctrl = TextEditingController(text: _currentServerUrl);
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Row(
          children: [
            const Icon(Icons.settings_ethernet, color: AppColors.amber, size: 20),
            const SizedBox(width: 8),
            Text(globalLanguage.t('server_settings'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              globalLanguage.isTagalog ? 'Pumili ng connection profile o ilagay ang server address:' : 'Select connection profile or enter server address:',
              style: const TextStyle(fontSize: 13, color: AppColors.inkSoft),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: ctrl,
              style: const TextStyle(fontSize: 13),
              decoration: const InputDecoration(
                hintText: 'https://...',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                ActionChip(
                  label: const Text('Online Server'),
                  onPressed: () => ctrl.text = AppConfig.ngrokUrl,
                ),
                ActionChip(
                  label: const Text('Local Network'),
                  onPressed: () => ctrl.text = AppConfig.localUrl,
                ),
              ],
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft))),
          ElevatedButton(
            onPressed: () async {
              await AppConfig.setBaseUrl(ctrl.text);
              await _loadState();
              if (ctx.mounted) Navigator.pop(ctx);
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(globalLanguage.t('server_saved'))));
              }
            },
            child: Text(globalLanguage.t('save')),
          ),
        ],
      ),
    );
  }

  void _handleLogout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(globalLanguage.t('prof_logout'), style: const TextStyle(fontWeight: FontWeight.bold, color: AppColors.ink)),
        content: Text(globalLanguage.t('prof_logout_confirm')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(globalLanguage.t('cancel'), style: const TextStyle(color: AppColors.inkSoft))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.redDanger, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(globalLanguage.t('prof_logout')),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    await AppConfig.setUser(null);
    if (!mounted) return;
    final profiles = await AppConfig.getRememberedProfiles();
    if (!mounted) return;
    if (profiles.isNotEmpty) {
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const PinLoginScreen()),
        (route) => false,
      );
    } else {
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (route) => false,
      );
    }
  }

  String _formatRoleTitle(String? role) {
    switch (role) {
      case 'inventory_staff':
        return globalLanguage.isTagalog ? 'Kawani ng Bodega (Inventory Staff)' : 'Warehouse & Inventory Staff';
      case 'field_supervisor':
        return globalLanguage.isTagalog ? 'Superbisor ng Sasakyan (Field Supervisor)' : 'Field Fleet Supervisor';
      case 'admin':
        return globalLanguage.isTagalog ? 'Administrador ng Sistema (Admin)' : 'System Administrator';
      case 'driver_helper':
      default:
        return globalLanguage.isTagalog ? 'Drayber / Humihiling (Requester)' : 'Fleet Requester / Personnel';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      appBar: buildWebStyleAppBar(
        context: context,
        activeTitle: globalLanguage.t('prof_title'),
        user: widget.user,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 96),
        children: [
          // User Profile Card
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: AppColors.charcoal2,
                    child: Text(
                      (widget.user['full_name'] ?? widget.user['username'] ?? 'U')[0].toUpperCase(),
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AppColors.amberOnDark),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          widget.user['full_name'] ?? widget.user['username'] ?? 'User',
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppColors.ink),
                        ),
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2.5),
                              decoration: BoxDecoration(
                                color: AppColors.charcoal2,
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: Text(
                                _formatRoleTitle(widget.user['role']),
                                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.amberOnDark),
                              ),
                            ),
                          ],
                        ),
                        if (widget.user['employee_id'] != null) ...[
                          const SizedBox(height: 3),
                          Text(
                            'ID: ${widget.user['employee_id']}${widget.user['position'] != null ? " • ${widget.user['position']}" : ""}',
                            style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 12),

          // Dedicated Language Preference Card
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.translate, color: AppColors.amber, size: 22),
                      const SizedBox(width: 8),
                      Text(
                        globalLanguage.t('prof_language_section'),
                        style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppColors.ink),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  const Center(child: LanguageToggleBar()),
                ],
              ),
            ),
          ),

          const SizedBox(height: 12),

          // 4-Digit PIN Quick Settings
          Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              leading: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppColors.amberTint,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppColors.amberBorder),
                ),
                child: const Icon(Icons.pin_rounded, color: AppColors.amber, size: 22),
              ),
              title: Text(
                globalLanguage.t('pin_change'),
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink),
              ),
              trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkSoft),
              onTap: () async {
                final pinSet = await showDialog<bool>(
                  context: context,
                  builder: (_) => PinSetupDialog(
                    token: widget.user['token']?.toString() ?? '',
                    hasExistingPin: widget.user['has_pin'] == true || widget.user['has_pin'] == 1 || widget.user['has_pin'] == '1',
                  ),
                );
                if (pinSet == true) {
                  widget.user['has_pin'] = true;
                  await AppConfig.setUser(widget.user);
                  await AppConfig.saveRememberedProfile(widget.user, hasPin: true);
                  if (mounted) setState(() {});
                }
              },
            ),
          ),

          const SizedBox(height: 12),

          // App Version & Check for Updates Card
          Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              leading: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppColors.blueTint,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AppColors.blueBorder),
                ),
                child: const Icon(Icons.system_update_rounded, color: AppColors.blueInfo, size: 22),
              ),
              title: Text(
                globalLanguage.isTagalog ? 'Suriin ang Update ng App' : 'Check for App Updates',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink),
              ),
              subtitle: const Text(
                'v${AppUpdateChecker.currentVersionName} • Online Cloud Ready',
                style: TextStyle(fontSize: 12, color: AppColors.inkSoft),
              ),
              trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.inkSoft),
              onTap: () => AppUpdateChecker.checkAndShowPrompt(context, manual: true),
            ),
          ),

          const SizedBox(height: 12),

          // Offline Queue Section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(globalLanguage.t('prof_offline_sync'), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink)),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                        decoration: BoxDecoration(
                          color: _offlineQueue.isEmpty ? AppColors.greenTint : AppColors.amberTint,
                          borderRadius: BorderRadius.circular(4),
                          border: Border.all(color: _offlineQueue.isEmpty ? AppColors.greenBorder : AppColors.amberBorder),
                        ),
                        child: Text(
                          '${_offlineQueue.length} pending',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w600,
                            color: _offlineQueue.isEmpty ? AppColors.greenOk : AppColors.amber,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.amber,
                      foregroundColor: Colors.white,
                      minimumSize: const Size(double.infinity, 48),
                    ),
                    onPressed: (_offlineQueue.isEmpty || _isSyncing) ? null : _syncQueue,
                    icon: _isSyncing
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Icon(Icons.sync, size: 18),
                    label: Text(_isSyncing ? (globalLanguage.isTagalog ? 'Nag-si-sync...' : 'Syncing...') : (globalLanguage.isTagalog ? 'I-sync Ngayon ang Queue' : 'Sync Queue Now')),
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 12),

          // Server Configuration Card
          Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: _openServerSettings,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text(globalLanguage.t('server_settings'), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.ink)),
                              const SizedBox(width: 6),
                              const Icon(Icons.edit_outlined, size: 14, color: AppColors.amber),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(_currentServerUrl, style: const TextStyle(fontSize: 12, color: AppColors.inkSoft)),
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right, color: AppColors.inkSoft),
                  ],
                ),
              ),
            ),
          ),

          const SizedBox(height: 20),

          // Logout Button
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.redDanger,
              side: const BorderSide(color: AppColors.redBorder, width: 1.5),
              minimumSize: const Size(double.infinity, 50),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: _handleLogout,
            icon: const Icon(Icons.logout, size: 20),
            label: Text(globalLanguage.t('prof_logout'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          ),
        ],
      ),
    );
  }
}
