<?php
/**
 * Deactivation feedback popup (Acowebs feedback UI kit v1.0.0 markup). Needs acowebs-feedback-ui.css.
 *
 * Printed hidden on the Plugins screen by AWDP_Feedback::render_deactivation_modal();
 * assets/js/awdp-deactivation-feedback.js opens it from this plugin's Deactivate link.
 *
 * @var string  $prefix      Plugin prefix, keeps the ids unique next to other Acowebs plugins.
 * @var string  $plugin_name Plugin name shown in the title.
 * @var string  $user_name   Admin's display name (pre-fills Name).
 * @var string  $user_email  Admin's email (pre-fills Email).
 * @var array[] $reasons     value => array( label, placeholder ).
 */

if (!defined('ABSPATH')) {
    exit;
}

$awdp_id = 'acofb-' . $prefix;
?>
<div class="acofb acofb-overlay" id="<?php echo esc_attr($awdp_id); ?>-deactivation" data-acofb="deactivation" hidden>
  <div class="acofb-modal" role="dialog" aria-modal="true" tabindex="-1" style="outline:none" aria-labelledby="<?php echo esc_attr($awdp_id); ?>-dx-title" aria-describedby="<?php echo esc_attr($awdp_id); ?>-dx-desc">
    <form class="acofb-modal-form" novalidate>
      <div class="acofb-modal-head">
        <span class="acofb-badge" aria-hidden="true">
          <svg viewBox="-8 -1.5 86 86" focusable="false"><path fill="#00DFB1" fill-rule="evenodd" d="M2.85642 22.0022C6.72119 18.0569 13.0525 17.9916 16.9978 21.8564L41.4978 45.8564C43.4052 47.7249 44.4861 50.2785 44.4999 52.9485C44.5136 55.6185 43.4591 58.1831 41.5711 60.0711L21.5711 80.0711C17.6658 83.9763 11.3342 83.9763 7.42894 80.0711C3.52369 76.1658 3.52369 69.8342 7.42894 65.9289L20.2846 53.0733L3.0022 36.1436C-0.943088 32.2788 -1.00836 25.9475 2.85642 22.0022Z"/><path fill="#00B2FF" fill-rule="evenodd" d="M60.1791 3.124C64.5289 7.36505 64.6171 14.3293 60.376 18.6791L48.4585 30.9022L65.7782 48.2218C70.0739 52.5176 70.0739 59.4824 65.7782 63.7782C61.4824 68.0739 54.5176 68.0739 50.2218 63.7782L25.2218 38.7782C20.9645 34.5208 20.9209 27.6318 25.124 23.3209L44.624 3.3209C48.8651 -1.02889 55.8293 -1.11705 60.1791 3.124Z"/><path fill="#0A84FF" fill-rule="evenodd" d="M43.5968 57.1543L25.2218 38.7786C22.1371 35.6937 21.2645 31.2271 22.6168 27.3607L41.4978 45.8572C43.4052 47.7257 44.4861 50.2794 44.4999 52.9495C44.5074 54.4143 44.1935 55.8473 43.5968 57.1543Z"/></svg>
        </span>
        <div>
          <h2 class="acofb-title" id="<?php echo esc_attr($awdp_id); ?>-dx-title">
            <?php
            /* translators: %s: plugin name. */
            echo esc_html(sprintf(__('Quick question before you deactivate %s', 'aco-woo-dynamic-pricing'), $plugin_name));
            ?>
          </h2>
          <p class="acofb-sub" id="<?php echo esc_attr($awdp_id); ?>-dx-desc"><?php esc_html_e('If you have a moment, tell us why. The Acowebs team reads every answer and uses it to improve the plugin.', 'aco-woo-dynamic-pricing'); ?></p>
        </div>
      </div>

      <div class="acofb-modal-body">
        <fieldset class="acofb-reasons">
          <legend class="acofb-sr">
            <?php
            /* translators: %s: plugin name. */
            echo esc_html(sprintf(__('Why are you deactivating %s?', 'aco-woo-dynamic-pricing'), $plugin_name));
            ?>
          </legend>

          <?php
          $awdp_i = 0;
          foreach ($reasons as $awdp_value => $awdp_reason) :
              $awdp_i++;
              ?>
          <div class="acofb-reason">
            <input class="acofb-radio" type="radio" name="reason" id="<?php echo esc_attr($awdp_id . '-r' . $awdp_i); ?>" value="<?php echo esc_attr($awdp_value); ?>" required>
            <label class="acofb-reason-card" for="<?php echo esc_attr($awdp_id . '-r' . $awdp_i); ?>"><?php echo esc_html($awdp_reason[0]); ?></label>
            <div class="acofb-details">
              <label class="acofb-sr" for="<?php echo esc_attr($awdp_id . '-d' . $awdp_i); ?>"><?php esc_html_e('Tell us more (optional)', 'aco-woo-dynamic-pricing'); ?></label>
              <textarea class="acofb-textarea" id="<?php echo esc_attr($awdp_id . '-d' . $awdp_i); ?>" name="reason_details" rows="3" maxlength="5000" placeholder="<?php echo esc_attr($awdp_reason[1]); ?>"></textarea>
            </div>
          </div>
          <?php endforeach; ?>
        </fieldset>

        <div class="acofb-contact">
          <input class="acofb-check" type="checkbox" name="consent" id="<?php echo esc_attr($awdp_id); ?>-contact" value="1" checked>
          <label class="acofb-check-label" for="<?php echo esc_attr($awdp_id); ?>-contact"><?php esc_html_e('Acowebs can contact me about this feedback', 'aco-woo-dynamic-pricing'); ?></label>
          <div class="acofb-contact-fields">
            <div class="acofb-field">
              <label class="acofb-label" for="<?php echo esc_attr($awdp_id); ?>-name"><?php esc_html_e('Name', 'aco-woo-dynamic-pricing'); ?></label>
              <input class="acofb-input" type="text" id="<?php echo esc_attr($awdp_id); ?>-name" name="name" value="<?php echo esc_attr($user_name); ?>" maxlength="255" autocomplete="name">
            </div>
            <div class="acofb-field">
              <label class="acofb-label" for="<?php echo esc_attr($awdp_id); ?>-email"><?php esc_html_e('Email', 'aco-woo-dynamic-pricing'); ?></label>
              <input class="acofb-input" type="email" id="<?php echo esc_attr($awdp_id); ?>-email" name="email" value="<?php echo esc_attr($user_email); ?>" maxlength="320" autocomplete="email">
            </div>
          </div>
        </div>

        <p class="acofb-msg acofb-msg--error" data-acofb-error role="alert"></p>

        <p class="acofb-privacy">
          <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V6H4a1.5 1.5 0 0 0-1.5 1.5v6A1.5 1.5 0 0 0 4 15h8a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 12 6h-.5V4.5A3.5 3.5 0 0 0 8 1Zm2 5V4.5a2 2 0 1 0-4 0V6h4Z"/></svg>
          <span><?php esc_html_e('We only send this if you click Send: your answer, your site address, name and language, the plugin version and your WordPress, WooCommerce and PHP versions. Your name and email are included only while the box above is ticked.', 'aco-woo-dynamic-pricing'); ?> <a class="acofb-link" href="https://acowebs.com/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy policy', 'aco-woo-dynamic-pricing'); ?><span class="acofb-sr"> <?php esc_html_e('(opens in a new tab)', 'aco-woo-dynamic-pricing'); ?></span></a></span>
        </p>
      </div>

      <div class="acofb-modal-foot">
        <button type="submit" class="acofb-btn acofb-btn--primary acofb-btn--send" data-acofb-action="send"><?php esc_html_e('Send & deactivate', 'aco-woo-dynamic-pricing'); ?></button>
        <button type="button" class="acofb-btn acofb-btn--text" data-acofb-action="skip"><?php esc_html_e('Skip & deactivate', 'aco-woo-dynamic-pricing'); ?></button>
        <button type="button" class="acofb-btn acofb-btn--text acofb-btn--cancel" data-acofb-action="cancel"><?php esc_html_e('Cancel', 'aco-woo-dynamic-pricing'); ?></button>
      </div>
    </form>
  </div>
</div>
