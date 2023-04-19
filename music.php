<?php
// avoid escapeshellcmd() problems with PHP using ASCII
setlocale(LC_CTYPE, "en_US.UTF-8");

/**
 *	@var array|false Array of all possible configuration parameters, from `music.defaults.ini`
 *  as well as `music.ini`.
 **/
$ini = parse_ini_file("music.defaults.ini", true, INI_SCANNER_RAW);
if (file_exists("music.ini")) {
	$ini = ini_merge($ini, parse_ini_file("music.ini", true, INI_SCANNER_RAW));
}

/** @var array|mixed Configuration parameters for the `[server]` section in the INI files. */
$cfg = $ini["server"];
$ext = explode(",", $cfg["ext_songs"] . "," . $cfg["ext_images"]);
$img = explode(",", $cfg["ext_images"]);

/** @var array|mixed Configuration parameters for the `[streamer]` section in the INI files. */
$strcfg = $ini["streamer"];

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Content-Type: application/javascript; charset=utf-8");

if (isset($_GET["dl"]) && !in_array("..", explode("/", $_GET["dl"]))) {
	$dl = urldecode(trim($_GET["dl"], "/"));
	if (is_dir($dl)) {
		if (!chdir($dl)) {
			die("Could not open folder: " . $dl);
		}
		return_zip($dl, ["."], false);
	} elseif (file_exists($dl)) {
		header("Content-Type: " . mime_content_type($dl));
		header("Content-Length: " . filesize($dl));
		header('Content-Disposition: attachment; filename="' . basename($dl) . '"');
		readfile($dl);
		exit();
	} else {
		die("File not found: " . $dl);
	}
} elseif (isset($_GET["dlpl"])) {
	$plname = urldecode($_GET["dlpl"]);
	$plfile = $cfg["playlistdir"] . "/" . $plname . ".mfp.json";
	if (!file_exists($plfile)) {
		die("Playlist not found: " . $plfile . PHP_EOL);
	}
	/** @var mixed Playlist object */
	$pl = json_decode(json_decode(file_get_contents($plfile)));
	if (is_object($pl)) {
		$pl = $pl->playlist;
	}
	$files = [];
	$filenames = "";
	foreach ($pl as $song) {
		$path = $cfg["root"] . "/" . $song->path;
		if (!file_exists($path)) {
			die("Song not found: " . $path . PHP_EOL);
		}
		array_push($files, $path);
		$filenames .= basename($song->path) . "\r\n";
	}
	$m3u = sys_get_temp_dir() . "/" . $plname . ".m3u";
	if (!file_put_contents($m3u, $filenames)) {
		$m3u = false;
	}
	return_zip($plname, $files, $m3u);
} elseif (isset($_GET["pl"])) {
	$playlists = [];
	if (is_dir($cfg["playlistdir"])) {
		$scan = scandir($cfg["playlistdir"]);
		foreach ($scan as $f) {
			if (substr($f, -8) == "mfp.json") {
				$playlists[substr($f, 0, -9)] = json_decode(file_get_contents($cfg["playlistdir"] . "/" . $f));
			}
		}
	}
	die(json_encode($playlists));
}
/** @var mixed Playlist object */
$pl = json_decode(file_get_contents("php://input"), true);
if (isset($pl["name"])) {
	if ($_SERVER["REQUEST_METHOD"] == "DELETE") {
		if (chdir($cfg["playlistdir"])) {
			foreach (glob($pl["name"] . ".mfp.*") as $f) {
				unlink($f);
			};
		}
		exit();
	}
	$name = $cfg["playlistdir"] . "/" . $pl["name"] . ".mfp.json";
	if (!is_dir($cfg["playlistdir"])) {
		mkdir($cfg["playlistdir"]);
	}
	if (file_exists($name)) {
		rename($name, $name . "." . time());
	}
	die(file_put_contents($name, json_encode($pl["songs"])));
}

// handle request for streaming (gwyneth 20230416)
if (isset($_GET["stream"])) {
	error_log("[DEBU] mfp: received request to stream music named <" . $pl["music"] . "> to streamer: <"
		. (!empty($strcfg["streamer_command"]) ?: "---empty---") . ">\n", 4);
	if (!empty($strcfg["streamer_command"]) && isset($pl["music"])) {
		// POST body has a JSON with the song name (hopefully) (gwyneth 20230416)
		$path = $cfg["root"] . "/" . $pl["music"];
		if (!file_exists($path)) {
			die("Song not found: " . $path . PHP_EOL);
		}
		$rawStreamerCommand = $strcfg["streamer_command"];
		// Parse special variables inside the command.
		// Use escapeshellcmd() on the replacements, just because who knows what users may
		// creatively pass (deliberately or not!) to ffmpeg... (gwyneth 20230417)
		$streamerCommand = str_replace("%filename%", escapeshellarg($path), $rawStreamerCommand);
		$streamerCommand = str_replace("%streaming_protocol%", escapeshellarg($strcfg["streaming_protocol"]), $streamerCommand);
		$streamerCommand = str_replace("%streamer_url%", escapeshellarg($strcfg["streamer_url"]), $streamerCommand);
		error_log("[INFO] mfp: launching streamer for <" . $streamerCommand . ">\n", 4);
		// `nohup` will launch ffmpeg in the background, so as not to interrupt the web server.
		// stderr and stdout will get redirected to /dev/null
		// `printf $!` will echo the PID of the spawned process (using `echo` seems to be problematic under
		//  some Linux implementations)
		// Finally, with luck, we'll even get a result code!
		try {
			/** @var int Result code from spawned process; usually, 0 means okay */
			$resCode = -1;
			/** @var int Spawned process ID. Assigned here to fix scope issues below. */
			$pid = -1;
			/** @var {array.<string>} Output from command, captured in an array of lines. */
			$op = array();
			/** @var string|false Last outputted line from the spawned `ffmpeg` (false if spawn failed) */
			$output = exec('nohup ' . $streamerCommand . ' > /dev/null 2>&1 & printf $!', $op, $resCode);
			if ($output === false) {
				error_log("[WARN] mfp: no output from spawn, no way to know if it succeeded");
				die("Unknown error");
			}
			/** @var integer Process ID from the nohup'd `exec()` call */
			if (!empty($op) && is_array($op)) {
				$pid = (int)$op[0];
				// Note: $pid should be the same as $output, if $output !== false)
			}
			if ($pid < 0 || $resCode > 0) {
				// something failed which we didn't manage to catch!
				error_log(sprintf("[ERRO] mfp: spawning `ffmpeg`: unknown error, PID was %d, returning code was %d, last line of output was '%s'", $pid, $resCode, $output), 4);
				die("Confusing error; see server logs");
			}
		} catch (Exception $e) {
			error_log("[ERRO] mfp: spawning `ffmpeg` caused exception: " . $e->getMessage(), 4);
			die("Spawning `ffmpeg` caused exception: " . $e->getMessage() . PHP_EOL);
		}
		if ($output === FALSE) {
			die("Spawning `ffmpeg` failed miserably; reason unknown!" . PHP_EOL);
		}
		// die("Sending '" . $pl["music"] . "' to streamer");
		die();	// no message back, so that the JavaScript doesn't think this is an error! (gwyneth 20230418)
	} else {
		// no streamer configured, but nevertheless a command was sent to stream?
		die("No streamer configured or music file not found");
	}
}

if (!isset($_GET["reload"])) {
	foreach ($ini["client"] as $key => $value) {
		echo (stristr($key, ".") ? "" : "var ") . $key . "=" . $value . ";" . PHP_EOL;
	};
	// for streamer INI config (gwyneth 20221208)
/* 	foreach ($ini["streamer"] as $key => $value) {
		echo (stristr($key, ".") ? "" : "var ") . $key . "=" . $value . ";" . PHP_EOL;
	}; */
	// We need only push_to_streamer:
	if (!empty($ini["streamer"]["push_to_streamer"])) {
		echo "var push_to_streamer=" . $ini["streamer"]["push_to_streamer"] . ";" . PHP_EOL;
	} else {
		echo "\/\/ Invalid or inexisting push_to_streamer" . PHP_EOL;
	}
}

$dir = $cfg["root"];
if (isset($_GET["play"]) && !in_array("..", explode("/", $_GET["play"]))) {
	$dir = trim($_GET["play"], "/");
	if (!file_exists($dir)) {
		die('var root="' . $cfg["root"] . '/";' . PHP_EOL . 'var library={"' . $cfg["notfound"] . '":""}');
	}
	if (!is_dir($dir)) {
		$files = [];
		$files[$dir] = ""; // Add file
		$scan = scandir(dirname($dir)); // Scan parent folder for cover
		foreach ($scan as $f) {
			$ext = strtolower(substr($f, strrpos($f, ".") + 1));
			if (in_array($ext, $GLOBALS["img"])) {
				$files[$f] = "";
				break;
			}
		}
		echo 'var root="";' . PHP_EOL . 'var library={"\/":' . json_encode($files) . "};";
		exit();
	}
}

/** @var string Path to music library (a JSON file) */
$lib_path = $cfg["playlistdir"] . "/library.json";
if (!$cfg["cache"] || isset($_GET["play"]) || isset($_GET["reload"]) || !file_exists($lib_path)) {
	$lib = json_encode(tree($dir, 0));
	if ($cfg["cache"] && !isset($_GET["play"])) {
		if (!is_dir($cfg["playlistdir"])) {
			mkdir($cfg["playlistdir"]);
		}
		file_put_contents($lib_path, $lib);
	}
} else {
	$lib = file_get_contents($lib_path);
}

echo 'var root="' . $dir . '/";' . PHP_EOL . "var library=" . $lib;

/**
 * Merge two lists of parameters set in `.INI` files.
 * This is used to begin with the `music.defaults.ini` parameter set,
 * and allow users to override them with their own parameters instead.
 * Handles sets of sets (applying itself recursively).
 *
 * @param  string[]       $ini Base set of parameters
 * @param  mixed|string[] $usr Additional sets of parameters (works recursively)
 *
 * @return string[] Array of merged parameters
 */
function ini_merge($ini, $usr)
{
	foreach ($usr as $k => $v) {
		if (is_array($v)) {
			$ini[$k] = ini_merge($ini[$k], $usr[$k]);
		} else {
			$ini[$k] = $v;
		}
	}
	return $ini;
}

/**
 * Generates a listing of folders and files in them,
 * respecting the configured valid extensions.
 *
 * @param  string  $dir   Directory name to search for.
 * @param  integer $depth How deep to go inside the directory tree.
 *
 * @return mixed|boolean  May return an array of arrays (directories and subfolders) or `false` if nothing found.
 */
function tree($dir, $depth)
{
	$scan = scandir($dir);
	$files = [];
	$tree = [];
	$hasmusic = false;

	foreach ($scan as $f) {
		if (substr($f, 0, 1) == ".") {
			continue;
		}
		if (is_dir("$dir/$f")) {
			if ($depth < $GLOBALS["cfg"]["maxdepth"]) {
				$subfolder = tree("$dir/$f", $depth + 1);
				if ($subfolder) {
					$tree[$f] = $subfolder;
				}
			}
		} else {
			$ext = strtolower(substr($f, strrpos($f, ".") + 1));
			if (in_array($ext, $GLOBALS["ext"])) {
				$files[$f] = "";
				if (!in_array($ext, $GLOBALS["img"])) {
					$hasmusic = true;
				}
			}
		}
	}

	if ($hasmusic) {
		$tree["/"] = $files;
	}
	if (count((array) $tree) > 0) {
		return $tree;
	} else {
		return false;
	}
}

function return_zip($name, $paths, $pl)
{
	$list_path = tempnam(sys_get_temp_dir(), "mfp_");
	$list = fopen($list_path, "w");
	if (substr(php_uname(), 0, 7) == "Windows") {
		foreach ($paths as $path) {
			fwrite($list, ($pl ? "./" : "") . $path . "\r\n");
		}
		$cmd = "7z a dummy -tzip -mx1 -so -i@" . escapeshellarg($list_path);
	} else {
		foreach ($paths as $path) {
			fwrite($list, $path . "\n");
		}
		$stdin = ["file", $list_path, "r"];	// $stdin unused? (gwyneth 20221127)
		$cmd = "zip - -0 " . ($pl ? "-j" : "-r") . " -@ <" . escapeshellarg($list_path);
	}
	if ($pl) {
		fwrite($list, $pl);
	}
	fclose($list);
	$descriptorspec = [["pipe", "r"], ["pipe", "w"], ["pipe", "a"]];
	$zip_proc = proc_open($cmd, $descriptorspec, $pipes);
	if (!$zip_proc) {
		die("Error creating zip");
	}
	header("Content-type: application/zip");
	header('Content-disposition: attachment; filename="' . basename($name) . '.zip"');
	$err = fread($pipes[2], 8192);
	fpassthru($pipes[1]);
	fclose($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[0]);
	$res = proc_close($zip_proc);
	if ($res != 0) {
		error_log("zip error (" . $res . "): " . $err . PHP_EOL);
		http_response_code(500);
	}
	if ($pl) {
		unlink($pl);
	}
	unlink($list_path);
	exit();
}
