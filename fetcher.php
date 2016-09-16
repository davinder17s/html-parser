<?php
set_time_limit(0);
class HtmlParser {
    public $resources_list = [];
    public $css = [];
    public $fetcher = null;

    public function parse($html)
    {
        $content = $html;
        preg_match_all('/<link([^>]+)>/i', $content, $link_tags);
        foreach ($link_tags[0] as $link) {
            preg_match_all('/href=["\']([^"\']+)["\']/i', $link, $link_hrefs);
            foreach ($link_hrefs[1] as $href) {
                preg_match_all('/rel=["\']([^"\']+)["\']/i', $link, $link_type);
                if (!empty($link_type[1][0]) && strtolower($link_type[1][0]) == 'stylesheet') {
                    $this->css[] = $href;
                } else {
                    $this->resources_list[] = $href;
                }
            }
            $content = str_replace($link, '', $content);
        }

        preg_match_all('/<script([^>]+)>/i', $content, $js_tags);
        foreach ($js_tags[0] as $js_tag) {
            preg_match_all('/src=["\']([^"\']+)["\']/i', $js_tag, $js_src);
            foreach ($js_src[1] as $src) {
                $this->resources_list[] = $src;
            }
            $content = str_replace($js_tag, '', $content);
        }

        preg_match_all('/<img([^>]+)>/i', $content, $img_tags);
        foreach ($img_tags[0] as $img_tag) {
            preg_match_all('/src=["\']([^"\']+)["\']/i', $img_tag, $img_srces);
            foreach ($img_srces[1] as $src) {
                if (strpos($src, 'data:') === false) {
                    $this->resources_list[] = $src;
                }
            }
            foreach ($img_srces[0] as $src) {
                $content = str_replace($src, '', $content);
            }
        }

        preg_match_all('/.+\.(jpg|jpeg|svg|gif|png|ico)/i', $content, $matches);
        foreach ($matches[0] as $i => $match) {
            preg_match_all('/["\']([^"\']+)?/i', $match, $other);
            foreach ($other[1] as $sub_matches) {
                if (preg_match('/\.(jpg|jpeg|svg|gif|png|ico)/i', $sub_matches)) {
                    if (preg_match('/url(.+)?\(/i', $sub_matches, $sub_matches_url)) {
                        $this->resources_list[] = substr($sub_matches, strpos($sub_matches, '(') + 1);
                    } else {
                        $this->resources_list[] = $sub_matches;
                    }
                }
            }
        }
    }
}

class CssParser {
    public $resources = [];
    public function parse($content)
    {
        preg_match_all('/url([^;]+);/i', $content, $property_matches);

        foreach ($property_matches[1] as $line) {
            preg_match_all('/\(([^\)]+)\)/i', $line, $line_matches);
            foreach ($line_matches[1] as $url) {
                if (strpos($url, ' ') === false && strpos($url, '.') !== false) {
                    $url = str_replace('"', '', $url);
                    $url = str_replace("'", '', $url);
                    if (strpos($url, 'data:') === false) {
                        $this->resources[] = $url;
                    }
                }
            }
        }
    }
}

class Fetcher {
    public $pages = ['/'];
    public $base_url = '';
    public $save_dir = 'download';
    public $ua = "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36";

    public $download_success = [];
    public $download_fail = [];

    protected function getResource($url)
    {
        $res = curl_init($url);
        curl_setopt($res, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($res, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($res, CURLOPT_USERAGENT, $this->ua);
        curl_setopt($res, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($res, CURLOPT_TIMEOUT, 300);
        curl_setopt($res, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($res);
        if (curl_error($res) == 0) {
            $this->download_success[] = $url;
            curl_close($res);
            return $output;
        }
        $this->download_fail[] = $url;
        curl_close($res);
        return '';
    }

    protected function prepare()
    {
        $this->base_url = rtrim($this->base_url, '/') . '/';
        $this->save_dir = rtrim($this->save_dir, '/') . '/';
        $pages = $this->pages;
        foreach ($pages as $i => $page) {
            $pages[$i] = ltrim($page, '/');
        }
        $this->pages = $pages;
    }

    protected function buildLocalPath($online_url = '')
    {
        $path = ltrim(parse_url($online_url, PHP_URL_PATH), '/');
        return $path;
    }

    protected function normalizeUrl($url)
    {
        $url = str_replace($this->base_url, '', $url);
        $url = str_replace(substr($this->base_url, strpos($this->base_url, '//')), '', $url);
        return $url;
    }

    protected function fetchPage($page)
    {
        $content = $this->getResource($this->base_url . $page);
        return $content;
    }

    public function start()
    {
        $this->prepare();

        foreach ($this->pages as $page) {
            $content = $this->fetchPage($page);
            $save_page_as = ($page == '')?'index.html': $page;
            $this->save_file($this->buildLocalPath($this->base_url. $save_page_as), $content);

            $this->parse_page($content);
        }
    }

    protected function parse_page($content)
    {
        $html_parser = new HtmlParser();
        $html_parser->parse($content);
        $resources = $html_parser->resources_list;

        $css_files = $html_parser->css;
        foreach ($css_files as $css_file) {
            $normalizedUrl = $this->normalizeUrl($css_file);
            if (strpos($normalizedUrl, 'http') === 0) {
                continue;
            }
            // skip
            if (file_exists($this->buildLocalPath($this->base_url . $normalizedUrl))) {
                //continue;
            }

            $css_content = $this->getResource($this->base_url . $normalizedUrl);
            $this->save_file($this->buildLocalPath($this->base_url . $normalizedUrl), $css_content);

            $css_parser = new CssParser();
            $css_parser->parse($css_content);
            $sub_resources = $css_parser->resources;

            foreach ($sub_resources as $sub_resource) {
                if (strpos($sub_resource, 'http') === 0) {
                    continue;
                }
                $new_url =  dirname($normalizedUrl) . '/' . $this->normalizeUrl($sub_resource);


                if (strpos($new_url, '.css') !== false) {
                    $new_url_content = $this->getResource($this->base_url . $new_url);
                    $this->save_file($this->buildLocalPath($this->base_url . $new_url), $new_url_content);

                    $css_parser = new CssParser();
                    $css_parser->parse($new_url_content);
                    $sub_sub_resources = $css_parser->resources;

                    foreach ($sub_sub_resources as $sub_sub_resource) {
                        if (strpos($sub_sub_resource, 'http') === 0) {
                            continue;
                        }
                        $new_new_url = dirname($this->normalizeUrl($this->base_url . $new_url)).'/' . $this->normalizeUrl($sub_sub_resource);
                        $resources[] = $new_new_url;
                    }
                } else {
                    $resources[] = $new_url;
                }
            }
        }

        foreach ($resources as $resource) {
            // skip
            if (file_exists($this->buildLocalPath($this->base_url . $resource))) {
                //continue;
            }

            $content = $this->getResource($this->base_url . $resource);
            $this->save_file($this->buildLocalPath($this->base_url . $resource), $content);
        }
    }

    protected function save_file($path, $content)
    {
        $path = explode('?', $path);
        $path = $path[0];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
}
$f = new Fetcher();

//----------------- User config starts here -----------------------
$f->base_url = 'http://demo/site';
$f->pages = ['/index.html'];
$f->save_dir = 'somesaved';

//---------------- User config end here ----------------------------
$f->start();

echo '--------- Success -------------' . PHP_EOL;
foreach ($f->download_success as $a) {
    echo '[v] ' . $a . PHP_EOL;
}
echo PHP_EOL;
echo '---------- Fail ---------------' . PHP_EOL;
foreach ($f->download_fail as $a) {
    echo '[x] ' . $a . PHP_EOL;
}