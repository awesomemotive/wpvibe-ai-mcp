<?php
/** Approval proofs are always verified against the public keys shipped in the plugin. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-wpvibe-op-proof-v2.php';

class WPVibe_Op_Proof extends WPVibe_Op_Proof_V2 {}
