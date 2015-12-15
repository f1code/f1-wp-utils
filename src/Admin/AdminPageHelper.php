<?php
/**
 * AdminPageHelper.php
 * Created By: nico
 * Created On: 10/17/2015
 */

namespace F1\WPUtils\Admin;

/**
 * Helper for admin pages in Wordpress.
 * Note this can be used either as a base class of the plugin's admin page, or as an instantiated helper class.
 * When used as a base class, the settings can be added (with addSettings) either in the constructor or by
 * overriding the adminInit function.
 *
 * @package F1\Core\WP
 */
class AdminPageHelper
{
    private $settingsName;
    private $settingsLabel;
    private $optionPageHookSuffix;
    private $settings = [];
    /**
     * @var bool
     */
    private $multiSite;
    /**
     * @var callable
     */
    private $sanitizeOptionCallback;

    function __construct($settingsName, $settingsLabel, $multiSite = false)
    {
        $this->settingsName = $settingsName;
        $this->settingsLabel = $settingsLabel;
        $this->multiSite = $multiSite;
    }

    /**
     * Register WP hooks to render admin page.
     * Should be called inside of WP_Loaded hook, but can be called either after or before adding the settings.
     *
     * @param string $pluginName - If specified, this will register a "settings" link for the plugin.
     */
    public function registerOptionPage($pluginName = null)
    {
        add_action('admin_init', array(&$this, 'adminInit'));
        if ($this->multiSite) {
            add_action('network_admin_menu', array(&$this, 'networkAdminMenu'));
            add_action('update_wpmu_options', array(&$this, 'adminUpdateOptions'));
        } else {
            add_action('admin_menu', array(&$this, 'adminMenu'));
        }
        if ($pluginName) {
            add_filter("plugin_action_links_" . $pluginName, array(&$this, 'settingsLink'));
        }
    }

    /**
     * Create plugin settings link
     *
     * @param $links
     * @return mixed
     */
    public function settingsLink($links)
    {
        $settings = '<a href="options-general.php?page=' . $this->settingsName . '">Settings</a>';
        array_unshift($links, $settings);
        return $links;
    }

    /**
     * Register a new setting
     *
     * @param string $name
     * @param string $label
     * @param callable $renderer
     * @param array $args
     */
    public function addSetting($name, $label, $renderer = null, $args = null)
    {
        if (!$renderer)
            $renderer = array($this, 'createSettingsTextbox');
        $this->settings[] = array('name' => $name, 'label' => $label, 'renderer' => $renderer,
            'args' => $args);
    }

    public function adminInit()
    {
        register_setting($this->settingsName, $this->settingsName, array($this, 'onSanitizeOptions'));
        add_settings_section('default', $this->settingsLabel, null, $this->settingsName);
        foreach ($this->settings as $setting) {
            $args = array('name' => $setting['name']);
            if ($setting['args']) {
                $args = array_merge($args, $setting['args']);
            }
            add_settings_field($setting['name'], $setting['label'],
                $setting['renderer'], $this->settingsName, 'default', $args);
        }

    }

    public function networkAdminMenu()
    {
        if (current_user_can('manage_options')) {
            add_submenu_page('settings.php', $this->settingsLabel, $this->settingsLabel, 'manage_options',
                $this->settingsName, array(&$this, 'outputNetworkPluginSettingsPage'));
        }
    }

    public function adminMenu()
    {
        if (current_user_can('manage_options')) {
            $this->optionPageHookSuffix = add_options_page($this->settingsLabel, $this->settingsLabel, 'manage_options',
                $this->settingsName, array($this, 'outputPluginSettingsPage'));
            add_action('admin_enqueue_scripts', array(&$this, 'checkEnqueueScripts'));
        }
    }

    /**
     * Used for network settings page
     */
    public function adminUpdateOptions()
    {
        if (isset($_GET['settings']) && $_GET['settings'] == $this->settingsName) {
            $cleanPost = $_POST;
            if (function_exists('wp_magic_quotes')) {
                $cleanPost = array_map('stripslashes_deep', $cleanPost);
            }
            $options = $cleanPost[$this->settingsName];
            $options = $this->onSanitizeOptions($options);
            update_site_option($this->settingsName, $options);
            wp_redirect(network_admin_url('settings.php?updated=true&page=' . $this->settingsName));
            exit();
        }
    }

    public function outputNetworkPluginSettingsPage()
    {
        if (isset($_GET['updated'])) {
            ?>
            <div id="message" class="updated notice is-dismissible"><p><?php _e('Options saved.') ?></p></div><?php
        }
        /** @noinspection HtmlUnknownTarget */
        echo '<div class="wrap"><form method="POST" action="settings.php?settings=' . $this->settingsName . '">';

        // settings_field: not for multisite
//        settings_fields(self::RTI_BOOKER_SETTINGS);
        wp_nonce_field('siteoptions');
        do_settings_sections($this->settingsName);
        submit_button();

        $this->onOutputSettingsPage();

        echo '</form></div>';
    }

    public function outputPluginSettingsPage()
    {
        /** @noinspection HtmlUnknownTarget */
        echo '<div class="wrap"><form method="POST" action="option.php">';

        settings_fields($this->settingsName);
        do_settings_sections($this->settingsName);
        submit_button();

        $this->onOutputSettingsPage();

        echo '</form></div>';
    }

    public function createSettingsTextbox($args)
    {
        $optionName = $args['name'];
        $size = isset($args['size']) ? $args['size'] : 40;
        $placeholder = isset($args['placeholder']) ? esc_attr($args['placeholder']) : '';
        $option = $this->getOption($optionName);
        $value = isset($option) ? esc_attr($option) : '';
        echo "<input type='text' id='$optionName'
            name='" . esc_attr($this->settingsName) . "[$optionName]'
            value='$value' size='$size' placeholder='$placeholder'/>";
    }

    public function createSettingsCheckbox($args)
    {
        $optionName = $args['name'];
        $label = isset($args['label']) ? esc_html($args['label']) : '';
        $option = $this->getOption($optionName);
        $checked = !empty($option) ? 'checked' : '';
        echo "<input type='checkbox' id='$optionName' name='" . $this->settingsName . "[$optionName]' $checked/>";
        if ($label) {
            echo "<label for='$optionName'>$label</label>";
        }
    }

    public function getOption($optionName = null)
    {
        if ($this->multiSite) {
            $options = get_site_option($this->settingsName);
        } else {
            $options = get_option($this->settingsName);
        }
        if (!$optionName)
            return $options;

        if (is_array($options) && isset($options[$optionName]))
            return $options[$optionName];
        foreach ($this->settings as $setting) {
            if ($setting['name'] == $optionName) {
                if (!empty($setting['default']))
                    return $setting['default'];
                break;
            }
        }
        return null;
    }


    /**
     * Check if admin scripts should be output, according to the page's suffix
     *
     * @param $hookSuffix
     */
    public function checkEnqueueScripts($hookSuffix)
    {
        if ($hookSuffix == $this->optionPageHookSuffix) {
            $this->onEnqueueScripts();
        }
    }


    ///////////////////////////////////////////////////////////////////
    /////////////// OVERRIDABLE API ///////////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /**
     * This can be overridden to enqueue scripts needed by the admin page
     */
    protected function onEnqueueScripts()
    {

    }

    /**
     * Can be overridden to provide a cleanup function for the options.
     *
     * @param array $options
     * @return array - sanitized options should be returned
     */
    public function onSanitizeOptions($options)
    {
        if ($this->sanitizeOptionCallback) {
            return call_user_func($this->sanitizeOptionCallback, $options);
        }
        return $options;
    }

    /**
     * Can be overridden to customize the page output.
     * This function is called inside of the form itself, right after the submit button.
     * Another option is to override the outputPluginSettingsPage function and replace the page completely.
     */
    protected function onOutputSettingsPage()
    {

    }

    ///////////////////////////////////////////////////////////////////
    /////////////// PUBLIC API ////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /**
     * Allow defining a callback for sanitize option call.
     * Callback will be passed the options which it should return.
     *
     * @param callable $callback
     */
    public function setOnSanitizeOptionCallback($callback)
    {
        $this->sanitizeOptionCallback = $callback;
    }

}