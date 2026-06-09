<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end self-service subscription shortcode: [hail_mail_subscribe].
 *
 * A logged-in WP user ticks which mailing lists to join/leave. Because the user is
 * acting on their own behalf this is genuine consent, so it runs on content.write
 * (gave_consent=true) — no studio scope needed.
 *
 * Security model:
 *   - Logged-in only; the acted-on email is ALWAYS the current user's, read
 *     server-side — never taken from the request (so user A can't touch user B).
 *   - Nonce-protected AJAX (logged-in action only, no nopriv).
 *   - Submitted list ids are validated against the org's subscribable set
 *     (show_on_outputs, non-SMS) server-side, regardless of what the form offered.
 *
 * Subscribe/unsubscribe map to Hail's soft-unsubscribe semantics:
 *   subscribe existing -> PUT mail/lists/{id}/subscribers (clears unsubscribed_date)
 *   subscribe new      -> POST organisations/{org}/mail/subscribers
 *   unsubscribe        -> DELETE mail/lists/{id}/subscribers (sets unsubscribed_date)
 */
class Hail_Mail_Connect_Shortcodes {

    public function __construct() {
        add_shortcode( 'hail_mail_subscribe', array( $this, 'render_subscribe' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_hail_mail_subscribe', array( $this, 'ajax_subscribe' ) );
    }

    public function register_assets() {
        wp_register_style( 'hail-mail-connect-public', HAIL_MAIL_CONNECT_URL . 'assets/css/public.css', array(), HAIL_MAIL_CONNECT_VERSION );
        wp_register_script( 'hail-mail-connect-public', HAIL_MAIL_CONNECT_URL . 'assets/js/public.js', array( 'jquery' ), HAIL_MAIL_CONNECT_VERSION, true );
        wp_localize_script( 'hail-mail-connect-public', 'hailMailConnect', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'hail_mail_subscribe' ),
        ) );
    }

    /* ------------------------------------------------------------------ *
     * Front-end render
     * ------------------------------------------------------------------ */

    public function render_subscribe( $atts ) {
        // Members-only: never render for unauthenticated visitors.
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'lists'  => '',
            'title'  => __( 'Manage your email subscriptions', 'hail-mail-connect' ),
            'button' => __( 'Save preferences', 'hail-mail-connect' ),
        ), $atts, 'hail_mail_subscribe' );

        $api = Hail_Mail_Connect::instance()->api;

        if ( ! $api->is_connected() || ! $api->has_scope( 'content.write' ) ) {
            return $this->admin_only_notice( __( 'Hail Mail is not connected (or lacks the content.write scope), so the subscription form is hidden.', 'hail-mail-connect' ) );
        }

        $offered = $this->offered_lists( $api, $atts['lists'] );
        if ( is_wp_error( $offered ) ) {
            return $this->admin_only_notice( __( 'Could not load mailing lists from Hail.', 'hail-mail-connect' ) );
        }
        if ( empty( $offered ) ) {
            return '<div class="hmc-subscribe hmc-subscribe--notice"><p>' . esc_html__( 'There are no subscription lists available right now.', 'hail-mail-connect' ) . '</p></div>';
        }

        $user    = wp_get_current_user();
        $contact = $api->find_contact_by_email( $user->user_email );
        $current = is_array( $contact ) ? $this->current_subscribed_list_ids( $contact ) : array();

        wp_enqueue_style( 'hail-mail-connect-public' );
        wp_enqueue_script( 'hail-mail-connect-public' );

        ob_start();
        ?>
        <form class="hmc-subscribe" data-hmc-subscribe>
            <?php if ( '' !== $atts['title'] ) : ?>
                <h3 class="hmc-subscribe__title"><?php echo esc_html( $atts['title'] ); ?></h3>
            <?php endif; ?>
            <p class="hmc-subscribe__intro"><?php echo esc_html( sprintf( __( 'Subscriptions for %s', 'hail-mail-connect' ), $user->user_email ) ); ?></p>

            <ul class="hmc-subscribe__lists">
                <?php foreach ( $offered as $list ) :
                    $id      = (string) ( $list['id'] ?? '' );
                    $name    = $list['name'] ?? $id;
                    $checked = in_array( $id, $current, true );
                    ?>
                    <li class="hmc-subscribe__item">
                        <label>
                            <input type="checkbox" name="lists[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?> />
                            <span class="hmc-subscribe__name"><?php echo esc_html( $name ); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="hmc-subscribe__actions">
                <button type="submit" class="hmc-subscribe__save"><?php echo esc_html( $atts['button'] ); ?></button>
                <span class="hmc-subscribe__message" role="status" aria-live="polite"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ *
     * AJAX
     * ------------------------------------------------------------------ */

    public function ajax_subscribe() {
        check_ajax_referer( 'hail_mail_subscribe', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'hail-mail-connect' ) ) );
        }

        $api = Hail_Mail_Connect::instance()->api;
        if ( ! $api->is_connected() || ! $api->has_scope( 'content.write' ) ) {
            wp_send_json_error( array( 'message' => __( 'Subscriptions are temporarily unavailable.', 'hail-mail-connect' ) ) );
        }

        $user  = wp_get_current_user();
        $email = $user->user_email; // server-side identity — never from the request

        // Validate submitted ids against the org's subscribable set (the real boundary).
        $allowed   = $this->subscribable_list_ids( $api );
        $submitted = isset( $_POST['lists'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['lists'] ) ) : array();
        $desired   = array_values( array_intersect( $allowed, $submitted ) );

        $contact = $api->find_contact_by_email( $email );
        if ( is_wp_error( $contact ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not reach Hail. Please try again.', 'hail-mail-connect' ) ) );
        }

        $errors = array();

        // Diagnostic: a checked list that isn't in the subscribable allow-list is
        // silently dropped — surface it so a mismatch doesn't look like a no-op.
        $dropped = array_diff( $submitted, $allowed );
        if ( ! empty( $dropped ) ) {
            error_log( 'Hail Mail Connect: submitted lists not in subscribable set, ignored: ' . implode( ',', $dropped ) );
        }

        $contact_id = is_array( $contact ) ? ( $contact['id'] ?? '' ) : '';
        $current    = is_array( $contact ) ? array_values( array_intersect( $allowed, $this->current_subscribed_list_ids( $contact ) ) ) : array();
        $to_add     = array_diff( $desired, $current );
        $to_remove  = array_diff( $current, $desired );

        $use_studio = $api->has_scope( 'studio' );

        foreach ( $to_add as $list_id ) {
            if ( $contact_id ) {
                // EXISTING contact (always the case on re-subscribe): reactivate via the
                // content.write add-existing endpoint, which upserts the pivot with
                // unsubscribed_date = null AND removed_at = null — a full reactivation.
                // We must NOT use studio here: its addMailList only clears removed_at and
                // leaves unsubscribed_date set, so a previously-unsubscribed member would
                // stay "Unsubscribed" and the checkbox would never stick. No opt-in email
                // is sent for an existing contact (no createContact / VerifyEmailJob).
                $r = $api->add_existing_subscribers_to_list( $list_id, array( $contact_id ) );
                if ( is_wp_error( $r ) ) {
                    $errors[] = $this->log_op_error( 'add ' . $list_id, $r );
                }
            } elseif ( $use_studio ) {
                // BRAND-NEW contact: studio bypasses the opt-in / verification flow.
                $r = $api->studio_add_subscribers( $list_id, array( array(
                    'email'      => $email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                ) ) );
                if ( is_wp_error( $r ) ) {
                    $errors[] = $this->log_op_error( 'studio-add ' . $list_id, $r );
                } else {
                    // studio returns 200 with per-contact results even on failure.
                    foreach ( (array) ( $r['results'] ?? array() ) as $res ) {
                        if ( ( $res['status'] ?? '' ) !== 'added' ) {
                            $detail = 'studio-add ' . $list_id . ': ' . ( $res['error'] ?? 'failed' );
                            error_log( 'Hail Mail Connect subscribe error — ' . $detail );
                            $errors[] = $detail;
                        }
                    }
                }
            } else {
                // Brand-new contact, no studio: fall back to the verifying create endpoint.
                $r = $api->create_subscriber( $email, $user->first_name, $user->last_name, array( $list_id ), true );
                if ( is_wp_error( $r ) ) {
                    $errors[] = $this->log_op_error( 'create ' . $list_id, $r );
                }
            }
        }

        // Removals are always content.write (studio has no remove endpoint). A brand-new
        // contact has nothing to remove.
        foreach ( $to_remove as $list_id ) {
            if ( '' === $contact_id ) {
                continue;
            }
            $r = $api->remove_subscribers_from_list( $list_id, array( $contact_id ) );
            if ( is_wp_error( $r ) ) {
                $errors[] = $this->log_op_error( 'remove ' . $list_id, $r );
            }
        }

        if ( ! empty( $errors ) ) {
            $message = __( 'Some changes could not be saved. Please try again.', 'hail-mail-connect' );
            // Admins (or any logged-in user on a WP_DEBUG dev install) see the underlying
            // Hail error to aid debugging; the public on production never does.
            if ( current_user_can( 'manage_options' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
                $message .= ' [' . implode( ' | ', $errors ) . ']';
            }
            wp_send_json_error( array( 'message' => $message ) );
        }

        wp_send_json_success( array(
            'message'    => __( 'Your subscription preferences have been saved.', 'hail-mail-connect' ),
            'subscribed' => $desired,
        ) );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** Lists to display: the org's subscribable lists, optionally restricted by the shortcode `lists` attr. */
    private function offered_lists( $api, $lists_attr ) {
        $resp = $api->get_mail_lists();
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $all      = $this->collection( $resp );
        $restrict = array_filter( array_map( 'trim', explode( ',', (string) $lists_attr ) ) );

        $offered = array();
        foreach ( $all as $list ) {
            $id = (string) ( $list['id'] ?? '' );
            if ( '' === $id ) {
                continue;
            }
            if ( ! empty( $restrict ) ) {
                if ( ! in_array( $id, $restrict, true ) ) {
                    continue;
                }
            } elseif ( ! $this->is_subscribable( $list ) ) {
                continue;
            }
            $offered[] = $list;
        }
        return $offered;
    }

    /** The org's subscribable list ids — the server-side allow-list for writes. */
    private function subscribable_list_ids( $api ) {
        $resp = $api->get_mail_lists();
        if ( is_wp_error( $resp ) ) {
            return array();
        }
        $ids = array();
        foreach ( $this->collection( $resp ) as $list ) {
            $id = (string) ( $list['id'] ?? '' );
            if ( '' !== $id && $this->is_subscribable( $list ) ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** A list is self-service subscribable if it's flagged for outputs and isn't an SMS list. */
    private function is_subscribable( $list ) {
        if ( ! empty( $list['sms_type'] ) ) {
            return false;
        }
        if ( isset( $list['show_on_outputs'] ) ) {
            return (bool) $list['show_on_outputs'];
        }
        return true; // field absent — default to subscribable
    }

    /** List ids the contact is currently subscribed to (membership with no unsubscribed_date). */
    private function current_subscribed_list_ids( $contact ) {
        $ids = array();
        foreach ( (array) ( $contact['lists'] ?? array() ) as $list ) {
            $unsub = $list['pivot']['unsubscribed_date'] ?? ( $list['unsubscribed_date'] ?? null );
            if ( empty( $unsub ) ) {
                $id = (string) ( $list['id'] ?? '' );
                if ( '' !== $id ) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    private function collection( $response ) {
        if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
            return $response['data'];
        }
        return is_array( $response ) ? $response : array();
    }

    /** Show a diagnostic only to admins; render nothing for the public. */
    private function admin_only_notice( $message ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<div class="hmc-subscribe hmc-subscribe--notice"><p><em>' . esc_html( $message ) . '</em></p></div>';
        }
        return '';
    }
}
