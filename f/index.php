<?php
$starttime = microtime();

if (is_dev() ) { 
    error_reporting(E_ALL ^ E_NOTICE);
    ini_set("display_errors", 1);
    #$_GET['url'] = "http://feeds.feedburner.com/sportsblogs/bloodyelbow.xml";
    $_GET['url'] = "http://www.wired.com";
} else {    
    error_reporting(0);
}

#@set_time_limit(30);
set_include_path(realpath(dirname(__FILE__).'/libraries').PATH_SEPARATOR.get_include_path());


//TODO: all of this needs to go in a function
if (!isset($_GET['url']) || $_GET['url'] == '') { 
    header('Location: /');
    exit;    
}

$url = $_GET['url'];
#error_log("URL in: $url");

if (!preg_match('!^https?://.+!i', $url)) {
	$url = 'http://'.$url;
}

//Breaks hiphopphp
$valid_url = filter_var($url, FILTER_VALIDATE_URL);
if ($valid_url !== false && $valid_url !== null && preg_match('!^https?://!', $valid_url)) {
	$url = filter_var($url, FILTER_SANITIZE_URL);
} else {
    error_log("Invalid URL supplied {$_GET['url']}");
	die('Invalid URL supplied');
}


// Check if the request is explicitly for an HTML page
$html_only = (isset($_GET['html']) && $_GET['html'] == 'true');
$max = 10;

// Set Expires header (10 minuets)
header('Expires: ' . gmdate('D, d M Y H:i:s', time()+(60*10)) . ' GMT');

// Set up HTTP agent
$http = new HumbleHttpAgent();
//$http->enableDebug();
$frontendOptions = array(
        'lifetime' => 30*60, // cache lifetime of 30 minutes
        'automatic_serialization' => true,
        'write_control' => false,
        'automatic_cleaning_factor' => 100,
        'ignore_user_abort' => false
        ); 
$backendOptions = array(
        'cache_dir' => $options->cache_dir.'/http-responses/', // directory where to put the cache files
        'file_locking' => false,
        'read_control' => true,
        'read_control_type' => 'strlen',
        'hashed_directory_level' => 0,
        'hashed_directory_umask' => 0777,
        'cache_file_umask' => 0664,
        'file_name_prefix' => 'ff'
        );
/*$httpCache = Zend_Cache::factory('Core', 'File', $frontendOptions, $backendOptions);
$http->useCache($httpCache); */

// Tidy config
$tidy_config = array(
        'clean' => true,
        'output-xhtml' => true,
        'logical-emphasis' => true,
        'show-body-only' => false,
        'wrap' => 0,
        'drop-empty-paras' => true,
        'drop-proprietary-attributes' => false,
        'enclose-text' => true,
        'enclose-block-text' => true,
        'merge-divs' => true,
        'merge-spans' => true,
        'char-encoding' => 'utf8',
        'hide-comments' => true
        );			

// Get RSS/Atom feed
if (!$html_only) {
	$feed = new SimplePie();
	$feed->set_feed_url($url);
	$feed->set_autodiscovery_level(SIMPLEPIE_LOCATOR_NONE);
	$feed->set_timeout(20);
	$feed->enable_cache(false);
	$feed->set_stupidly_fast(true);
	$feed->enable_order_by_date(false); // we don't want to do anything to the feed
	$feed->set_url_replacements(array());
	$result = $feed->init();
	if ($result && (!is_array($feed->data) || count($feed->data) == 0)) {
        error_log("No feed items found for $url");
		die('Sorry, no feed items found');
	}
}

// Extract content from HTML (if URL is not feed or explicit HTML request has been made)
if ($html_only || !$result) {
    require('libraries/simplehtmldom/simple_html_dom.php');

    $html = file_get_html($url);
    $feeds = Array();
    foreach ($html->find('link') as $element) {
        if ($element->type == 'application/rss+xml') {
            $feeds[] = $element->href;
        }
    }
    if (count($feeds) == 0) {
        //TODO: some kind of error handling system. Should probably output an RSS feed with the error.
        die('No feeds found on page');
    }
    //TODO: do something smarter than use just the first feed.
    $url = $feeds[0];
    $feed = new SimplePie();
    $feed->set_feed_url($url);
    $feed->set_autodiscovery_level(SIMPLEPIE_LOCATOR_NONE);
    $feed->set_timeout(20);
    $feed->enable_cache(false);
    $feed->set_stupidly_fast(true);
    $feed->enable_order_by_date(false); // we don't want to do anything to the feed
    $feed->set_url_replacements(array());
    $result = $feed->init();
    //$feed->handle_content_type();
    //$feed->get_title();
    if ($result && (!is_array($feed->data) || count($feed->data) == 0)) {
        error_log("No feed items found for $url ");
        die('Sorry, no feed items found');
    }

}

// Create full-text feed
$output = new FeedWriter(); //ATOM an option
$output->setTitle($feed->get_title());
$output->setDescription($feed->get_description());
$output->setLink($feed->get_link());
if ($img_url = $feed->get_image_url()) {
	$output->setImage($feed->get_title(), $feed->get_link(), $img_url);
}

// Request all feed items in parallel (if supported)
$items = $feed->get_items(0, $max);	
$urls = array();
foreach ($items as $item) {
	$permalink = htmlspecialchars_decode($item->get_permalink());
	$permalink = $http->validateUrl($permalink);
	if ($permalink) {
		$urls[] = $permalink;
	}
	$item->filteredPermalink = $permalink;
}

$http->fetchAll($urls);
$http->cacheAll();
foreach ($items as $item) {
	$permalink = $item->filteredPermalink;
	$newitem = $output->createNewItem();
	
    $newitem->setTitle(htmlspecialchars_decode($item->get_title()));
	
    if ($permalink !== false) {
		$newitem->setLink($permalink);
	} else {
		$newitem->setLink($item->get_permalink());
	}
	
	if ($permalink && $response = $http->get($permalink)) {
		$originalhtml = $response['body'];
		$html = convert_to_utf8($originalhtml, $response['headers']);
		// Run through Tidy (if it exists).
		if (function_exists('tidy_parse_string')) {
			$tidy = tidy_parse_string($originalhtml, $tidy_config, 'UTF8');
			if ( $tidy->cleanRepair()) {
			    $html = $tidy->value;
            }
		} else {
            //TODO  check this before we start or don't check at all
            error_log("ERROR: tidy is not installed");
        }

        //TODO: functions motherfucker, have you heard of them?
		$readability = new Readability($html, $permalink);
		$readability->init();
		$readability->clean($readability->getContent(), 'select');
        makeAbsolute($permalink, $readability->getContent());
        $content = $readability->getContent()->innerHTML;
        if ( /*stripos($url, 'rss.cnn.com') !== */ false) {
            $html = parse_cnn($originalhtml );
            
        } else if (stripos($url, 'fantasyflightgames.com')) {
            $end = strpos($content, "Comments (");
            $content = substr($content, 0, $end);
            $html = $content;
        } else if ($content == "<p>Sorry, Readability was unable to parse this page for content.</p>") {
            //error_log("readability could not parse $url");
            $html = $item->get_content() . "*";
        } else {
    		$html = $content;	
        }
	} else {

        $html .= $item->get_description();
    }
    $newitem->addElement('guid', $item->get_permalink(), array('isPermaLink'=>'true'));
    $newitem->setDescription($html);
    if ((int)$item->get_date('U') > 0) {
        $newitem->setDate((int)$item->get_date('U'));
    }
    if ($author = $item->get_author()) {
        $newitem->addElement('dc:creator', $author->get_name());
    }

	$output->addItem($newitem);
	unset($html);
    unset($originalhtml);
}


ob_start();
$output->genarateFeed();
$output = ob_get_contents();
ob_end_clean();
print $output;

function is_dev() {
    if (!isset($_SERVER['HTTP_HOST'])) {
        return true;
    }
    return  ($_SERVER['HTTP_HOST'] == 'dev.fullfeed.net');
}

function parse_cnn($html) {
    $include_start_key  ="<!--startclickprintinclude-->";
    $include_end_key = "<!--endclickprintinclude-->";
 
    $exclude_start_key = "<!--startclickprintexclude-->";
    $exclude_end_key = "<!--endclickprintexclude-->";
    
    $result = "";
    $copy = true;
    $start = time();
    for ($c=stripos($html, $include_start_key); $c<strlen($html); $c++) {
        if (substr($html, $c, strlen($include_start_key)) == $include_start_key) {
            $copy = true;
            $c += strlen($include_start_key);
        } else if (substr($html, $c, strlen($include_end_key)) === $include_end_key) {
            $copy = false;
            $c += strlen($include_end_key);
        } else if (substr($html, $c, strlen($exclude_start_key)) === $exclude_start_key) {
            $copy = false;
            $c += strlen($exclude_start_key);
        } else if (substr($html, $c, strlen($exclude_end_key)) === $exclude_end_key) {
            $copy = true;
            $c += strlen($exclude_end_key);
        }

        if ($copy) {
            $result .= $html[$c];
        }

        if (time() - $start > 5) {
            error_log("FATAL: parse_cnn took too long");
            #return $result;
        }

    }
    return $result;
}

function __autoload($class_name) {
	static $mapping = array(
		// Include SimplePie for RSS/Atom parsing
		'SimplePie' => 'simplepie/simplepie.class.php',
		'SimplePie_Misc' => 'simplepie/simplepie.class.php',		
		// Include FeedCreator for RSS/Atom creation
		'FeedWriter' => 'feedwriter/FeedWriter.php',
		'FeedItem' => 'feedwriter/FeedItem.php',
		// Include Readability for identifying and extracting content from URLs
		'Readability' => 'readability/Readability.php',
		// Include Humble HTTP Agent to allow parallel requests and response caching
		'HumbleHttpAgent' => 'humble-http-agent/HumbleHttpAgent.php',
		// Include IRI class for resolving relative URLs
		'IRI' => 'iri/iri.php',
		// Include Zend Cache to improve performance (cache results)
		'Zend_Cache' => 'Zend/Cache.php'
	);
	if (isset($mapping[$class_name])) {
		//echo "Loading $class_name\n<br />";
		require_once $mapping[$class_name];
		return true;
	} else {
		return false;
	}
}

// Convert $html to UTF8
// (uses HTTP headers and HTML to find encoding)
// adapted from http://stackoverflow.com/questions/910793/php-detect-encoding-and-make-everything-utf-8
function convert_to_utf8($html, $header=null)
{
    //error_log("convert_to_utf8(html, header)");
	$accept = array(
		'type' => array('application/rss+xml', 'application/xml', 'application/rdf+xml', 'text/xml', 'text/html'),
		'charset' => array_diff(mb_list_encodings(), array('pass', 'auto', 'wchar', 'byte2be', 'byte2le', 'byte4be', 'byte4le', 'BASE64', 'UUENCODE', 'HTML-ENTITIES', 'Quoted-Printable', '7bit', '8bit'))
	);
	$encoding = null;
	if ($html || $header) {
		if (is_array($header)) $header = implode("\n", $header);
		if (!$header || !preg_match_all('/^Content-Type:\s+([^;]+)(?:;\s*charset=([^;"\'\n]*))?/im', $header, $match, PREG_SET_ORDER)) {
			// error parsing the response
		} else {
			$match = end($match); // get last matched element (in case of redirects)
			if (!in_array(strtolower($match[1]), $accept['type'])) {
				// type not accepted
				// TODO: avoid conversion
			}
			if (isset($match[2])) $encoding = trim($match[2], '"\'');
		}
		if (!$encoding) {
			if (preg_match('/^<\?xml\s+version=(?:"[^"]*"|\'[^\']*\')\s+encoding=("[^"]*"|\'[^\']*\')/s', $html, $match)) {
				$encoding = trim($match[1], '"\'');
			} elseif(preg_match('/<meta\s+http-equiv=["\']Content-Type["\'] content=["\'][^;]+;\s*charset=([^;"\'>]+)/i', $html, $match)) {
				if (isset($match[1])) $encoding = trim($match[1]);
			}
		}
		if (!$encoding) {
			$encoding = 'utf-8';
		} else {
			if (!in_array($encoding, array_map('strtolower', $accept['charset']))) {
				// encoding not accepted
				// TODO: avoid conversion
			}
			if (strtolower($encoding) != 'utf-8') {
				if (strtolower($encoding) == 'iso-8859-1') {
					// replace MS Word smart qutoes
					$trans = array();
					$trans[chr(130)] = '&sbquo;';    // Single Low-9 Quotation Mark
					$trans[chr(131)] = '&fnof;';    // Latin Small Letter F With Hook
					$trans[chr(132)] = '&bdquo;';    // Double Low-9 Quotation Mark
					$trans[chr(133)] = '&hellip;';    // Horizontal Ellipsis
					$trans[chr(134)] = '&dagger;';    // Dagger
					$trans[chr(135)] = '&Dagger;';    // Double Dagger
					$trans[chr(136)] = '&circ;';    // Modifier Letter Circumflex Accent
					$trans[chr(137)] = '&permil;';    // Per Mille Sign
					$trans[chr(138)] = '&Scaron;';    // Latin Capital Letter S With Caron
					$trans[chr(139)] = '&lsaquo;';    // Single Left-Pointing Angle Quotation Mark
					$trans[chr(140)] = '&OElig;';    // Latin Capital Ligature OE
					$trans[chr(145)] = '&lsquo;';    // Left Single Quotation Mark
					$trans[chr(146)] = '&rsquo;';    // Right Single Quotation Mark
					$trans[chr(147)] = '&ldquo;';    // Left Double Quotation Mark
					$trans[chr(148)] = '&rdquo;';    // Right Double Quotation Mark
					$trans[chr(149)] = '&bull;';    // Bullet
					$trans[chr(150)] = '&ndash;';    // En Dash
					$trans[chr(151)] = '&mdash;';    // Em Dash
					$trans[chr(152)] = '&tilde;';    // Small Tilde
					$trans[chr(153)] = '&trade;';    // Trade Mark Sign
					$trans[chr(154)] = '&scaron;';    // Latin Small Letter S With Caron
					$trans[chr(155)] = '&rsaquo;';    // Single Right-Pointing Angle Quotation Mark
					$trans[chr(156)] = '&oelig;';    // Latin Small Ligature OE
					$trans[chr(159)] = '&Yuml;';    // Latin Capital Letter Y With Diaeresis
					$html = strtr($html, $trans);
				}
				$html = SimplePie_Misc::change_encoding($html, $encoding, 'utf-8');

			}
		}
	}
	return $html;
}

function makeAbsolute($base, $elem) {
    //error_log("makeAbsolute($base, $elem)");
	$base = new IRI($base);
	foreach(array('a'=>'href', 'img'=>'src') as $tag => $attr) {
		$elems = $elem->getElementsByTagName($tag);
		for ($i = $elems->length-1; $i >= 0; $i--) {
			$e = $elems->item($i);
			//$e->parentNode->replaceChild($articleContent->ownerDocument->createTextNode($e->textContent), $e);
			if ($e->hasAttribute($attr)) {
				$url = $e->getAttribute($attr);
				if (!preg_match('!https?://!i', $url)) {
					$absolute = IRI::absolutize($base, $url);
					if ($absolute) {
						$e->setAttribute($attr, $absolute);
					}
				}
			}
		}
	}
}



?>
