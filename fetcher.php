<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 15-09-2016
 * Time: 11:36
 */

class Fetcher{
    public $pages = [];
    public $extra_resources = [];
    public $base_url = [];
    public $save_dir = './saved';
    public $ua = "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36";
    protected $resources = [];

    public $fetched_resources = [];
    public $error_resources = [];

    protected function safe_name($url)
    {
        $ex = explode('?', str_replace($this->base_url, '', $url));
        return $ex[0];
    }
    protected function fetch_resource($url)
    {
        $res = curl_init($this->base_url . $url);
        curl_setopt($res, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($res, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($res, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($res, CURLOPT_TIMEOUT, 300);
        curl_setopt($res, CURLOPT_USERAGENT, $this->ua);

        $result = curl_exec($res);
        if (!curl_errno($res)) {
            $this->fetched_resources[] = $this->base_url . $url;
            return $result;
        } else {
            echo curl_error($res);
            $this->error_resources[] = $this->base_url . $url;
            return '';
        }
    }

    public function start()
    {
        set_time_limit(0);
        $this->save_dir  = rtrim($this->save_dir, '/\\') . '/';
        $this->base_url  = rtrim($this->base_url, '/\\') . '/';
        foreach ($this->pages as $pageurl) {
            $pagename = rtrim($pageurl, '/');
            if (!$this->is_file($pagename) || true) {
                $html = $this->fetch_resource($pagename);
                if ($pagename == '') {
                    $pagename = 'index.html';
                }
                $replacements = $this->get_resources($html);
                foreach ($replacements as $find => $replace) {
                    $html = str_replace($find, $replace, $html);
                }
                $this->save_file($pagename, $html);
            }
        }
    }

    protected function get_resources($content)
    {
        $regex = '/=["\']([^"]+)[\'"]/i';
        preg_match_all($regex, $content, $matches);
        $replacements = array();

        $resources = array();
        foreach ($matches[0] as $i => $match) {
            if (preg_match('/\.(js|ico|css|jpg|jpeg|png|gif)/i', $match)) {
                $css_regex = "/url\(['\"]?([^('|\))]+)['\"]?\)/i";
                if (preg_match($css_regex, $match)) {
                    preg_match_all($css_regex, $match, $matches_css);

                    foreach ($matches_css[1] as $i => $sub_url) {
                        $sub_resource_url = str_replace('"','', $matches_css[1][$i]);
                        $resources[] = $sub_resource_url;
                    }
                } else {
                    $resource_url = explode('?', $matches[1][$i]);
                    $resource_url = $resource_url[0];
                    if (strpos($resource_url, '//') === 0) {
                        $prefix = substr($this->base_url, 0, strpos($this->base_url, '//'));
                        $resource_url = $prefix . $resource_url;
                    }
                    if (strpos($resource_url, $this->base_url) === false && strpos($resource_url,'//') !== false) {
                        continue;
                    }
                    $resource_url = str_replace($this->base_url, '', $resource_url);
                    $resources[] = $resource_url;

                    $replacements[$matches[1][$i]] = $resource_url;
                }
            }
        }

        foreach ($resources as $resource) {
            // check for css
            if (!$this->is_file($resource)) {
                $content = $this->fetch_resource($resource);
                $this->save_file($resource, $content);

                $ext_url = explode('.', $resource);
                $ext = strtolower(end($ext_url));
                if ($ext == 'css') {
                    $css_regex = "/url\(['\"]?([^('|\))]+)['\"]?\)/i";
                    preg_match_all($css_regex, $content, $matches);
                    foreach ($matches[1] as $sub_url) {
                        $sub_resource_url = dirname($resource) .'/'. str_replace('"','', $sub_url);
                        if (!$this->is_file($sub_resource_url)) {
                            $sub_content = $this->fetch_resource($sub_resource_url);
                            $this->save_file($sub_resource_url, $sub_content);
                        }
                    }
                }
            }

        }

        return $replacements;
    }

    protected function save_file($url, $resource)
    {
        if (strpos($url, 'data:') !== false || strpos($url, ':') !== false) {
            return false;
        }
        $saved_name = $this->safe_name($url);
        if (!is_dir($this->save_dir . dirname($url))) {
            mkdir($this->save_dir . dirname($url), 0777, true);
        }
        file_put_contents($this->save_dir . $saved_name, $resource);
        return true;
    }

    protected function is_file($url)
    {
        $saved_name = $this->safe_name($url);
        if (is_file($this->save_dir . $saved_name)) {
            return true;
        } else {
            return false;
        }
    }
}

$f = new Fetcher();
//------------------------- User config start here ----------------------
$f->base_url = 'http://demo.deviserweb.com/appeo/appeo/';
$f->save_dir = 'appeo';
$f->pages = [
    '/'
];
//------------------------ End config -----------------------------------
$f->start();

echo '<pre>';
echo 'Finished' . PHP_EOL;
echo PHP_EOL;
echo '------------ Successful ------------' . PHP_EOL;
foreach ($f->fetched_resources as $resource) {
    echo '[v] '. $resource . PHP_EOL;
}
echo '------------ Failed ----------------' . PHP_EOL;
foreach ($f->error_resources as $resource) {
    echo '[x] '. $resource . PHP_EOL;
}