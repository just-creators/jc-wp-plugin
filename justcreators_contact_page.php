<?php
/**
 * JustCreators Contact Form
 * Zeigt ein Kontaktformular für Support-Anfragen
 */

// Shortcode für Kontakt-Seite
add_shortcode( 'jc-contact-form', function() {
    // Session starten falls nötig
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '.just-creators.de',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
    
    ob_start();
    
    $form_submitted = false;
    $validation_errors = [];
    $success_message = false;
    
    // Formular wurde abgesendet
    if ( isset( $_POST['jc_contact_nonce'] ) && wp_verify_nonce( $_POST['jc_contact_nonce'], 'jc_contact_action' ) ) {
        
        // Daten sanitizen
        $name = isset( $_POST['contact_name'] ) ? sanitize_text_field( $_POST['contact_name'] ) : '';
        $email = isset( $_POST['contact_email'] ) ? sanitize_email( $_POST['contact_email'] ) : '';
        $subject = isset( $_POST['contact_subject'] ) ? sanitize_text_field( $_POST['contact_subject'] ) : '';
        $message = isset( $_POST['contact_message'] ) ? sanitize_textarea_field( $_POST['contact_message'] ) : '';
        
        // Validierung
        if ( empty( $name ) || strlen( $name ) < 2 ) {
            $validation_errors[] = 'Bitte gib einen Namen ein (mindestens 2 Zeichen).';
        }
        
        if ( empty( $email ) || ! is_email( $email ) ) {
            $validation_errors[] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
        }
        
        if ( empty( $subject ) || strlen( $subject ) < 3 ) {
            $validation_errors[] = 'Bitte gib einen Betreff ein (mindestens 3 Zeichen).';
        }
        
        if ( empty( $message ) || strlen( $message ) < 10 ) {
            $validation_errors[] = 'Bitte gib einen Anliegen/eine Nachricht ein (mindestens 10 Zeichen).';
        }
        
        // Wenn keine Fehler, E-Mail versenden
        if ( empty( $validation_errors ) ) {
            $to = 'support@just-creators.de';
            $email_subject = '[JustCreators Support] ' . $subject;
            
            // E-Mail Body
            $email_body = sprintf(
                "Neue Kontaktanfrage von der JustCreators Website\n\n" .
                "==================================================\n" .
                "Name: %s\n" .
                "E-Mail: %s\n" .
                "Betreff: %s\n" .
                "==================================================\n\n" .
                "Anliegen/Nachricht:\n%s\n\n" .
                "==================================================\n" .
                "Datum: %s\n" .
                "IP-Adresse: %s\n",
                $name,
                $email,
                $subject,
                $message,
                current_time( 'Y-m-d H:i:s' ),
                isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'N/A'
            );
            
            // Header für E-Mail
            $headers = [
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . $name . ' <' . $email . '>',
                'Reply-To: ' . $email
            ];
            
            // E-Mail an Support versenden
            $mail_sent = wp_mail( $to, $email_subject, $email_body, $headers );
            
            if ( $mail_sent ) {
                $form_submitted = true;
                $success_message = true;
                
                // Optional: Bestätigungs-E-Mail an den Absender senden
                $user_subject = 'Wir haben deine Nachricht erhalten!';
                $user_body = sprintf(
                    "Hallo %s,\n\n" .
                    "vielen Dank für deine Kontaktanfrage!\n\n" .
                    "Betreff: %s\n\n" .
                    "Wir haben deine Nachricht erhalten und werden uns so schnell wie möglich bei dir melden.\n\n" .
                    "Viele Grüße,\n" .
                    "dein JustCreators Support Team",
                    $name,
                    $subject
                );
                
                $user_headers = [
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: support@just-creators.de'
                ];
                
                wp_mail( $email, $user_subject, $user_body, $user_headers );
            } else {
                $validation_errors[] = 'Fehler beim Versenden der E-Mail. Bitte versuche es später erneut.';
            }
        }
    }
    
    // CSS Styling
    ?>
    <style>
        .jc-contact-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(88, 101, 242, 0.15);
        }
        
        .jc-contact-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .jc-contact-header h2 {
            color: #ffffff;
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px 0;
        }
        
        .jc-contact-header p {
            color: #a0a8b8;
            font-size: 16px;
            margin: 0;
        }
        
        .jc-error {
            background-color: rgba(237, 66, 69, 0.15);
            border: 1px solid #ed4245;
            color: #f04747;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .jc-label {
            display: block;
            color: #dcddde;
            font-weight: 600;
            font-size: 15px;
            margin: 25px 0 10px 0;
        }
        
        .jc-input,
        .jc-textarea {
            width: 100%;
            padding: 12px 15px;
            background-color: #2a2a3e;
            border: 1px solid #3c3c54;
            color: #ffffff;
            font-size: 15px;
            border-radius: 8px;
            font-family: inherit;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .jc-input:focus,
        .jc-textarea:focus {
            outline: none;
            background-color: #333347;
            border-color: #5865F2;
            box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.1);
        }
        
        .jc-textarea {
            resize: vertical;
            min-height: 150px;
        }
        
        .jc-discord-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, #5865F2 0%, #4752C4 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            width: 100%;
        }
        
        .jc-discord-btn:hover {
            background: linear-gradient(135deg, #4752C4 0%, #3d4299 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(88, 101, 242, 0.3);
        }
        
        .jc-discord-btn:active {
            transform: translateY(0);
        }
        
        .jc-discord-logo {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }
        
        .jc-success {
            text-align: center;
            padding: 40px 20px;
            animation: slideUp 0.5s ease;
        }
        
        .jc-success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 0.6s ease;
        }
        
        .jc-success h3 {
            color: #43b581;
            font-size: 24px;
            margin: 20px 0 15px 0;
        }
        
        .jc-success p {
            color: #dcddde;
            font-size: 16px;
            margin: 10px 0;
            line-height: 1.6;
        }
        
        .jc-note {
            background-color: rgba(88, 101, 242, 0.08);
            border-left: 4px solid #5865F2;
            padding: 12px 15px;
            border-radius: 6px;
            color: #b5bac1;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .jc-field-error {
            display: block;
            color: #f04747;
            font-size: 13px;
            margin-top: 5px;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
    </style>
    
    <div class="jc-contact-container">
        <div class="jc-contact-header">
            <h2>💬 Kontaktiere uns</h2>
            <p>Wir helfen dir gerne weiter!</p>
        </div>
        
        <?php
        // Fehler anzeigen
        if ( ! empty( $validation_errors ) ) {
            foreach ( $validation_errors as $error ) {
                echo '<div class="jc-error">❌ ' . esc_html( $error ) . '</div>';
            }
        }
        
        // Erfolgsmeldung anzeigen
        if ( $success_message ) {
            ?>
            <div class="jc-success">
                <div class="jc-success-icon">✉️</div>
                <h3>Nachricht versendet!</h3>
                <p><strong>Vielen Dank für deine Kontaktanfrage!</strong></p>
                <p>📬 Wir haben deine E-Mail erhalten und werden uns so schnell wie möglich bei dir melden.</p>
                <p style="margin-top: 25px;">
                    <a href="<?php echo esc_url( get_permalink() ); ?>" style="color: #5865F2; text-decoration: none; font-weight: 600;">
                        ← Zurück zur Seite
                    </a>
                </p>
            </div>
            <?php
        } else {
            // Kontakt-Formular
            ?>
            <form method="post" id="jc-contact-form">
                <?php wp_nonce_field( 'jc_contact_action', 'jc_contact_nonce' ); ?>
                
                <label class="jc-label">👤 Name *</label>
                <input 
                    class="jc-input" 
                    type="text" 
                    name="contact_name" 
                    id="jc-contact-name" 
                    required 
                    placeholder="Dein vollständiger Name"
                    value="<?php echo isset( $_POST['contact_name'] ) ? esc_attr( sanitize_text_field( $_POST['contact_name'] ) ) : ''; ?>"
                />
                <span class="jc-field-error" id="jc-contact-name-error" style="display: none;"></span>
                
                <label class="jc-label">📧 E-Mail-Adresse *</label>
                <input 
                    class="jc-input" 
                    type="email" 
                    name="contact_email" 
                    id="jc-contact-email" 
                    required 
                    placeholder="deine@email.de"
                    value="<?php echo isset( $_POST['contact_email'] ) ? esc_attr( sanitize_email( $_POST['contact_email'] ) ) : ''; ?>"
                />
                <span class="jc-field-error" id="jc-contact-email-error" style="display: none;"></span>
                <div class="jc-note">
                    ℹ️ Deine E-Mail wird nur für die Antwort verwendet.
                </div>
                
                <label class="jc-label">📋 Betreff *</label>
                <input 
                    class="jc-input" 
                    type="text" 
                    name="contact_subject" 
                    id="jc-contact-subject" 
                    required 
                    placeholder="z. B. Frage zur Bewerbung"
                    value="<?php echo isset( $_POST['contact_subject'] ) ? esc_attr( sanitize_text_field( $_POST['contact_subject'] ) ) : ''; ?>"
                />
                <span class="jc-field-error" id="jc-contact-subject-error" style="display: none;"></span>
                
                <label class="jc-label">💭 Anliegen / Nachricht *</label>
                <textarea 
                    class="jc-textarea" 
                    name="contact_message" 
                    id="jc-contact-message" 
                    required 
                    placeholder="Erzähle uns, wie wir dir helfen können..."
                ><?php echo isset( $_POST['contact_message'] ) ? esc_textarea( sanitize_textarea_field( $_POST['contact_message'] ) ) : ''; ?></textarea>
                <span class="jc-field-error" id="jc-contact-message-error" style="display: none;"></span>
                <div class="jc-note">
                    ℹ️ Bitte sei so genau wie möglich, damit wir dir schneller helfen können.
                </div>
                
                <button type="submit" class="jc-discord-btn" style="margin-top: 30px;">
                    <svg class="jc-discord-logo" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                        <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                    </svg>
                    Nachricht absenden
                </button>
            </form>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('jc-contact-form');
                    
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            let isValid = true;
                            
                            // Validierung Name
                            const nameInput = document.getElementById('jc-contact-name');
                            const nameError = document.getElementById('jc-contact-name-error');
                            if (!nameInput.value.trim() || nameInput.value.trim().length < 2) {
                                nameError.textContent = 'Bitte gib einen Namen ein (mindestens 2 Zeichen).';
                                nameError.style.display = 'block';
                                isValid = false;
                            } else {
                                nameError.style.display = 'none';
                            }
                            
                            // Validierung E-Mail
                            const emailInput = document.getElementById('jc-contact-email');
                            const emailError = document.getElementById('jc-contact-email-error');
                            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                            if (!emailRegex.test(emailInput.value.trim())) {
                                emailError.textContent = 'Bitte gib eine gültige E-Mail-Adresse ein.';
                                emailError.style.display = 'block';
                                isValid = false;
                            } else {
                                emailError.style.display = 'none';
                            }
                            
                            // Validierung Betreff
                            const subjectInput = document.getElementById('jc-contact-subject');
                            const subjectError = document.getElementById('jc-contact-subject-error');
                            if (!subjectInput.value.trim() || subjectInput.value.trim().length < 3) {
                                subjectError.textContent = 'Bitte gib einen Betreff ein (mindestens 3 Zeichen).';
                                subjectError.style.display = 'block';
                                isValid = false;
                            } else {
                                subjectError.style.display = 'none';
                            }
                            
                            // Validierung Nachricht
                            const messageInput = document.getElementById('jc-contact-message');
                            const messageError = document.getElementById('jc-contact-message-error');
                            if (!messageInput.value.trim() || messageInput.value.trim().length < 10) {
                                messageError.textContent = 'Bitte gib ein Anliegen ein (mindestens 10 Zeichen).';
                                messageError.style.display = 'block';
                                isValid = false;
                            } else {
                                messageError.style.display = 'none';
                            }
                            
                            if (!isValid) {
                                e.preventDefault();
                                e.stopPropagation();
                            }
                        });
                        
                        // Live-Validierung bei Input
                        const inputs = form.querySelectorAll('.jc-input, .jc-textarea');
                        inputs.forEach(input => {
                            input.addEventListener('blur', function() {
                                const errorSpan = document.getElementById(this.id + '-error');
                                if (errorSpan && this.value.trim().length > 0) {
                                    errorSpan.style.display = 'none';
                                }
                            });
                        });
                    }
                });
            </script>
            <?php
        }
        ?>
    </div>
    
    <?php
    return ob_get_clean();
} );
