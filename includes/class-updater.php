<?php
class SHI_Updater {
    private $slug;
    private $plugin;
    private $github_repo;
    private $github_token;
    private $github_response;

    public function __construct($config = array()) {
        $this->slug = isset($config['slug']) ? $config['slug'] : '';
        $this->plugin = isset($config['plugin']) ? $config['plugin'] : '';
        $this->github_repo = isset($config['github_repo']) ? $config['github_repo'] : '';
        $this->github_token = isset($config['github_token']) ? $config['github_token'] : '';

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    private function get_repository_info() {
        if (!empty($this->github_response)) {
            return;
        }

        $request_uri = sprintf('https://api.github.com/repos/%s/releases/latest', $this->github_repo);
        
        $headers = array();
        if (!empty($this->github_token)) {
            $headers['Authorization'] = "token {$this->github_token}";
        }

        $response = wp_remote_get($request_uri, array('headers' => $headers));

        if (is_wp_error($response)) {
            return;
        }

        $response = json_decode(wp_remote_retrieve_body($response));
        
        if (!empty($response->tag_name)) {
            $this->github_response = $response;
        }
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $this->get_repository_info();

        if (empty($this->github_response)) {
            return $transient;
        }

        $doUpdate = version_compare($this->github_response->tag_name, $transient->checked[$this->plugin]);

        if ($doUpdate) {
            $package = $this->github_response->zipball_url;
            if (!empty($this->github_token)) {
                $package = add_query_arg(array('access_token' => $this->github_token), $package);
            }

            $obj = new stdClass();
            $obj->slug = $this->slug;
            $obj->new_version = $this->github_response->tag_name;
            $obj->url = $this->github_response->html_url;
            $obj->package = $package;
            $obj->tested = '6.4.3'; // 更新为你测试过的最新WordPress版本
            
            $transient->response[$this->plugin] = $obj;
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!empty($args->slug)) {
            if ($args->slug == $this->slug) {
                $this->get_repository_info();

                $plugin = new stdClass();
                $plugin->name = $this->github_response->name;
                $plugin->slug = $this->slug;
                $plugin->version = $this->github_response->tag_name;
                $plugin->author = '评论尸';
                $plugin->homepage = $this->github_response->html_url;
                $plugin->requires = '5.8';
                $plugin->tested = '6.4.3';
                $plugin->downloaded = 0;
                $plugin->last_updated = $this->github_response->published_at;
                $plugin->sections = array(
                    'description' => $this->github_response->body,
                    'changelog' => $this->get_changelog()
                );
                $plugin->download_link = $this->github_response->zipball_url;

                return $plugin;
            }
        }

        return $result;
    }

    private function get_changelog() {
        $response = wp_remote_get(sprintf(
            'https://raw.githubusercontent.com/%s/master/CHANGELOG.md',
            $this->github_repo
        ));

        if (is_wp_error($response)) {
            return '无法获取更新日志';
        }

        return wp_remote_retrieve_body($response);
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->plugin);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->active) {
            activate_plugin($this->plugin);
        }

        return $result;
    }
}
