<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Receives Open Graph protocol metadata (a.k.a social media metadata) from link
 *
 * @package    core
 * @copyright  2024 Team "the Z" https://github.com/Catalyst-QUT-2023 based on code from 2021 Jon Green <jgreen01@stanford.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\url;

defined('MOODLE_INTERNAL') || die();

/** Display none */
define('URLPREVIEW_DISPLAY_NONE', 0);
/** Display full frame */
define('URLPREVIEW_DISPLAY_FULL', 1);
/** Display slim frame */
define('URLPREVIEW_DISPLAY_SLIM', 2);
/** Display tool frame */
define('URLPREVIEW_DISPLAY_TOOL', 9);

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');

/**
 * Class unfurler
 * This class is responsible for unfurling URLs to extract and format metadata.
 */
class unfurler {
    /**
     * The URL preview.
     * @var urlpreview
     */
    public $preview = null;
    /**
     * The provided URL.
     * @var string
     */
    protected $url = '';
    /**
     * The canonical URL.
     * @var string
     */
    public $canonicalurl = '';
    /**
     * Indicates whether Noog metadata is enabled.
     * @var bool
     */
    protected $noogmetadata = true;
    /**
     * The response data.
     * @var string
     */
    protected $response;

    /**
     * Unfurler contructor.
     * @param string $url
     */
    public function __construct(string $url) {
        GLOBAL $DB;

        // TODO: Some baisc url checking / formatting to stop duplicates with /.
        $this->url = $url;

        $records = urlpreview::get_records_select($DB->sql_compare_text('url') . " = ?", [$this->url]);
        $id = array_key_first($records);

        if (!empty($id)) {
            $this->preview = $records[$id];
        } else {
            if (!$this->load_url()) {
                return;
            }

            $this->preview = new urlpreview();
            $this->extract_html_metadata($this->url, $this->response);
        }

        $this->save();
    }

    /**
     * Loads the URL.
     *
     * @return bool success
     */
    private function load_url() {
        // Initialize cURL session and load data.
        $curl = new \curl();
        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 5,
        ];
        $this->response = $curl->get($this->url, $options);

        $errorno = $curl->get_errno();
        if ($errorno === CURLE_OPERATION_TIMEOUTED) {
            echo get_string('urltimeout', 'moodle', $this->url);
            return false;
        }
        return true;
    }

    /**
     * Extract metadata from url.
     * @param string $url The URL from which to extract metadata.
     * @param string $response The response to with the extracted metadata.
     */
    public function extract_html_metadata(string $url, string $response): void {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $response);
        $metataglist = $doc->getElementsByTagName('meta');
        // Default html title.
        $titleelement = $doc->getElementsByTagName('title')->item(0);
        $h1element = $doc->getElementsByTagName('h1')->item(0);
        $h2element = $doc->getElementsByTagName('h2')->item(0);

        $this->preview->set('url', $url);
        $title = '';
        if ($titleelement) {
            $title = $titleelement->textContent;
        } else if ($h1element) {
            $title = $h1element->textContent;
        } else if ($h2element) {
            $title = $h2element->textContent;
        }
        $this->preview->set('title', $title);

        // Check mimetype.
        $mimetype = resourcelib_guess_url_mimetype($url);
        $imagemimetypes = ['image/gif', 'image/jpeg', 'image/png', 'image/svg+xml'];
        if (in_array($mimetype, $imagemimetypes)) {
            $this->preview->set('imageurl', $url);
        }

        // Iterate through meta tags.
        foreach ($metataglist as $metatag) {
            $propertyattribute = strtolower(s($metatag->getAttribute('property')));
            if (
                !empty($propertyattribute) &&
                preg_match ('/^og:\w/i', $propertyattribute) === 1
            ) {
                $this->noogmetadata = false;
                break;
            }
        }

        if ($this->noogmetadata) {
            return;
        }

        foreach ($metataglist as $metatag) {
            $propertyattribute = strtolower(s($metatag->getAttribute('property')));
            $contentattribute = $metatag->getAttribute('content');
            if (
                !empty($propertyattribute) &&
                !empty($contentattribute) &&
                preg_match ('/^og:\w/i', $propertyattribute) === 1
            ) {
                $sanitizedcontent = clean_param($contentattribute, PARAM_TEXT);
                switch ($propertyattribute) {
                    case 'og:title':
                        $this->preview->set('title', $sanitizedcontent);
                        break;
                    case 'og:site_name':
                        $this->preview->set('sitename', $sanitizedcontent);
                        break;
                    case 'og:image':
                        $imageurlparts = parse_url($contentattribute);
                        if (empty($imageurlparts['host']) && !empty($imageurlparts['path'])) {
                            $urlparts = parse_url($url);
                            $imageurl = $urlparts['scheme'] . '://' . $urlparts['host'] . $imageurlparts['path'];
                        } else {
                            $imageurl = clean_param($contentattribute, PARAM_URL);
                        }
                        $this->preview->set('imageurl', $imageurl);
                        break;
                    case 'og:description':
                        $this->preview->set('description', $sanitizedcontent);
                        break;
                    case 'og:url':
                        $sanitizedcontent = clean_param($contentattribute, PARAM_URL);
                        $this->canonicalurl = $sanitizedcontent;
                        break;
                    case 'og:type':
                        $sanitizedcontent = clean_param($contentattribute, PARAM_ALPHANUMEXT);
                        $this->preview->set('type', $sanitizedcontent);
                    default:
                        break;
                }
            }
        }
    }

    /**
     * Refreshes the metadata stored in the database.
     *
     * @return void
     */
    public function refresh(): void {
        if (!$this->load_url()) {
            return;
        }
        $this->extract_html_metadata($this->url, $this->response);
        $this->save(true);
    }

    /**
     * Saves unfurled data to the database.
     *
     * @param bool $refresh
     */
    private function save($refresh = false): void {
        // Save the linted data to the database using the persistent class.
        $id = $this->preview->get('id');
        if (!$id) {
            $this->preview->set('lastpreviewed', time());
            $this->preview->create();
            return;
        }

        $currenttime = time();
        $lastpreviewed = $this->preview->get('lastpreviewed');
        if ($refresh || ($currenttime - $lastpreviewed) > HOURSECS) {
            $this->preview->set('lastpreviewed', $currenttime);
            $this->preview->update();
        }
    }

    /**
     * Returns metadata used to render a preview.
     *
     * @return array
     */
    public function get_metadata(): array {
        return [
            'title' => $this->preview->get('title'),
            'sitename' => $this->preview->get('sitename'),
            'image' => $this->preview->get('imageurl'),
            'description' => $this->preview->get('description'),
            'canonicalurl' => $this->canonicalurl ?: $this->preview->get('url'),
        ];
    }

    /**
     * Renders an unfurled preview.
     *
     * @param int $display preview type
     * @param array $metadata
     * @return bool|string
     */
    public function render_preview(int $display, array $metadata = []) {
        GLOBAL $OUTPUT;

        switch ($display) {
            case URLPREVIEW_DISPLAY_FULL:
                $template = 'core/url_preview_card';
                break;
            case URLPREVIEW_DISPLAY_SLIM:
                $template = 'core/url_preview_slim';
                break;
            case URLPREVIEW_DISPLAY_TOOL:
                $template = 'tool_urlpreview/metadata';
                break;
            default:
                return '';
        }

        if (empty($metadata)) {
            $metadata = $this->get_metadata();
        }

        return $OUTPUT->render_from_template($template, $metadata);
    }

    /**
     * Returns list of available urlpreview options.
     * @param array $enabled List of options enabled in module configuration.
     * @param int|null $current Current display option for existing instances.
     * @return array of key=>name pairs
     */
    public static function urlpreview_get_displayoptions(array $enabled, ?int $current = null): array {
        if (isset($current) && is_numeric($current)) {
            $enabled[] = $current;
        }

        $options = [
            URLPREVIEW_DISPLAY_NONE => get_string('resourcedisplaynone'),
            URLPREVIEW_DISPLAY_FULL => get_string('resourcedisplayfull'),
            URLPREVIEW_DISPLAY_SLIM => get_string('resourcedisplayslim'),
        ];

        $result = [];
        foreach ($options as $key => $value) {
            if (in_array($key, $enabled)) {
                $result[$key] = $value;
            }
        }

        if (empty($result)) {
            // There should be always something in case admin misconfigures module.
            $result[URLPREVIEW_DISPLAY_NONE] = $options[URLPREVIEW_DISPLAY_NONE];
        }

        return $result;
    }
}
