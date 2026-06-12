<?php

/**
 * Content-Security-Policy for the admin area (Report-Only first).
 *
 * Phase 1 targets admin_domain only. Scripts are nonce-gated
 * (script-src 'self' 'nonce-…', no 'unsafe-inline'); inline styles are
 * permitted pragmatically (style-src 'unsafe-inline'). External origins the
 * admin UI legitimately reaches (OpenCPU, OSF, R-fiddle, Google Sheets) are
 * allowlisted for connect/frame/form-action. worker-src blob: is required by
 * the Ace code editors. Deliberately NO 'unsafe-eval': the prod build/ bundles
 * have none (only the dev-build/ webpack bundles use eval).
 *
 * Mode is driven by the `csp_mode` setting: 'off' | 'report-only' | 'enforce'.
 */
class Csp {

    /** Browser-facing origins the admin UI talks to via fetch/XHR. */
    const CONNECT_SRC = 'https://public.opencpu.org https://fiddle.rforms.org https://accounts.osf.io https://api.osf.io https://docs.google.com';

    /** Origins framed by the admin UI (OpenCPU knit output, OSF, fiddle). */
    const FRAME_SRC = 'https://public.opencpu.org https://fiddle.rforms.org https://accounts.osf.io https://docs.google.com';

    /** Cross-origin form POST targets (social share, OSF OAuth). */
    const FORM_ACTION = 'https://www.facebook.com https://twitter.com https://accounts.osf.io';

    /** Endpoint that receives violation reports (admin-domain relative). */
    const REPORT_URI = '/api/csp-report';

    /**
     * @return string one of 'off' | 'report-only' | 'enforce'
     */
    public static function mode() {
        $mode = Config::get('csp_mode', 'report-only');
        return in_array($mode, array('off', 'report-only', 'enforce'), true) ? $mode : 'report-only';
    }

    public static function isEnabled() {
        return self::mode() !== 'off';
    }

    /**
     * Response header name for the current mode (null when disabled).
     *
     * @return string|null
     */
    public static function headerName() {
        switch (self::mode()) {
            case 'enforce':
                return 'Content-Security-Policy';
            case 'report-only':
                return 'Content-Security-Policy-Report-Only';
            default:
                return null;
        }
    }

    /**
     * Build the policy string for a given per-request nonce.
     *
     * @param string $nonce base64url nonce from Site::getCspNonce()
     * @return string
     */
    public static function buildPolicy($nonce) {
        $n = "'nonce-" . $nonce . "'";
        $directives = array(
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "script-src 'self' " . $n,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "worker-src 'self' blob:",
            "connect-src 'self' " . self::CONNECT_SRC,
            "frame-src 'self' " . self::FRAME_SRC,
            "form-action 'self' " . self::FORM_ACTION,
            "frame-ancestors 'self'",
            "report-uri " . self::REPORT_URI,
        );
        return implode('; ', $directives);
    }

    /**
     * Normalize a decoded violation report into the fields we log. Accepts
     * the CSP Level 2 shape ({"csp-report": {kebab-case keys}}), the
     * Reporting API shape ({"type": "csp-violation", "body": {camelCase
     * keys}}), and a flat body in either casing. Unknown fields are dropped;
     * missing fields come back null.
     *
     * @param array $report decoded JSON request body
     * @return array
     */
    public static function extractReportFields($report) {
        if (isset($report['csp-report']) && is_array($report['csp-report'])) {
            $r = $report['csp-report'];
        } elseif (isset($report['body']) && is_array($report['body'])) {
            $r = $report['body'];
        } else {
            $r = $report;
        }
        return array(
            'document-uri'       => $r['document-uri']       ?? ($r['documentURL'] ?? null),
            'violated-directive' => $r['violated-directive'] ?? ($r['effectiveDirective'] ?? null),
            'blocked-uri'        => $r['blocked-uri']        ?? ($r['blockedURL'] ?? null),
            'source-file'        => $r['source-file']        ?? ($r['sourceFile'] ?? null),
            'line-number'        => $r['line-number']        ?? ($r['lineNumber'] ?? null),
        );
    }
}
