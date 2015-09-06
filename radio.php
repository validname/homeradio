<?php

require_once("config.php");

/////////////////// some functions

function convert_time_to_seconds($time) {
	global $debug;

	@list($hours,$mins,$secs) = explode(':',$time);
	if ( is_null($mins) ) { // no hours and mins
		$secs = $hours;
		$mins = 0;
		$hours = 0;
	}
	if ( is_null($secs) ) { // no hours
		$secs = $mins;
		$mins = $hours;
		$hours = 0;
	}
	return mktime($hours,$mins,$secs) - mktime(0,0,0);
}

function exec_mpd_command($cmd) {
	global $debug;

	$output = array();
	@exec("/usr/bin/mpc ".$cmd, $output, $exit_code);
	if ( $exit_code >0 ) {
		if ( $debug ) {
			echo "ERROR while executing mpc:\n";
			var_dump($output);
		}
		return false;
	} else {
		return $output;
	}
}

function parse_mpd_state($status) {
	global $debug;

	/*
default.aac
[playing] #2/4   0:53/4:08 (21%)
Updating DB (#4) ...
volume: n/a   repeat: on    random: off   single: off   consume: off
	*/

	$state = array();
	$state['state'] = 'stop';
	// search play state from first index
	foreach ( $status as $tmp_idx => $status_line ) {
		if ( preg_match( '/^\[(paused|playing)\]/', $status_line) ) {
			$tmp = preg_split( '/\s+/', $status_line );
			if ( $tmp[0] == '[playing]' ) {
				$state['state'] = 'play';
			}
			if ( $tmp[0] == '[paused]' ) {
				$state['state'] = 'pause';
			}
			$tmp_num = strtok($tmp[1], '/');
			$state['songid'] = intval( substr($tmp_num, 1) );
			$state['playlistlength'] = strtok('/');

			$state['time_sec'] = convert_time_to_seconds( strtok($tmp[2], '/') );
			$state['elapsed_sec'] = convert_time_to_seconds( strtok('/') );
		}
	}
	if ( $debug ) {
		echo "parsed mpd state: \n";
		var_dump($state);
	}
	return $state;
}

function switch_to_URL($URLs) {
	global $radio_config;
	global $debug;

	// oh, yeah! we just executing mpc: it's slow, unsecure and with low capabilities. But it's fast in development. ^_^
	$output = exec_mpd_command("status");
	if ( $output===false ) {
		return "cannot get mpd status";
	}
	$mpd_state = parse_mpd_state($output);
	if ( $mpd_state['state']=='stop' ) {
		// just clear playlist
		$output = exec_mpd_command("clear");
		if ( $output===false ) {
			return "cannot clear current playlist";
		}
		if ( $debug ) {
			echo "cleared playlist\n";
			$play_songid = "";
		}
	} else {
		// gently clear playlist later
		$current_songid = $mpd_state['songid'];
		$play_songid = $current_songid+1;
		// we will insert URL and default song after this songid
		$playlist_length = $mpd_state['playlistlength'];
	}

	if ( $debug ) {
		$output = exec_mpd_command(" -f '%position%. %file%' playlist");
		echo "Playlist before adding:\n";
		var_dump($output);
	}

	$added = 0;

	if ( isset($radio_config['default_song']) ) {
		$output = exec_mpd_command("insert ".$radio_config['default_song']);
		if ( $output===false ) {
			return "cannot insert default song into current playlist";
		}
		if ( $debug ) {
			echo "added default song\n";
		}
		$added++;
	}

	if( !is_array($URLs) ) {
		echo "URL is NOT array";
		$URLs = array( $URLs );
	}
	krsort($URLs);

	foreach( $URLs as $tmp_idx => $URL ) {
		$output = exec_mpd_command("insert $URL");
		if ( $output===false ) {
			return "cannot insert URL into current playlist";
		}
		if ( $debug ) {
			echo "added URL $URL\n";
		}
		$added++;
	}

	if ( $debug ) {
		$output = exec_mpd_command(" -f '%position%. %file%' playlist");
		echo "Playlist after adding:\n";
		var_dump($output);
	}

	// play our URL
	$output = exec_mpd_command("play ".$play_songid);
	if ( $output===false ) {
		return "cannot play URL from current playlist";
	}
	if ( $debug ) {
		echo "play URL\n";
	}

	if ( $mpd_state['state']!='stop' ) {
		// delete all songs from old default song to the end of playlist
		if ( $current_songid < $playlist_length ) {
			if ( $debug ) {
				echo "trim playlist end\n";
			}
			for ( $i=$playlist_length+$added; $i>$current_songid+$added; $i-- ) {
				$output = exec_mpd_command("del ".$i);
				if ( $output===false ) {
					return "cannot delete song #$i from current playlist";
				}
				if ( $debug ) {
					echo "deleted song #$i\n";
				}
			}
		}
		// delete all songs from begin of playlist to the old default song
		if ( $debug ) {
			echo "trim playlist start\n";
		}
		for ( $i=$current_songid; $i>=1; $i-- ) {
			$output = exec_mpd_command("del ".$i);
			if ( $output===false ) {
				return "cannot delete song #$i from current playlist";
			}
			if ( $debug ) {
				echo "deleted song #$i\n";
			}
		}
	}

	return true;
}

/////////////////// PAGE

$debug = false;
if ( isset($_REQUEST['debug']) ) {
	$debug = true;
}
$error = "";

if ( isset($_REQUEST['station_num']) ) {
	// switch to the specified station
	if ( $debug ) {
		echo "<pre>\n";
	}
	$station_num = intval($_REQUEST['station_num']);
	if ( isset($radio_config['stations'][$station_num]) ) {
		if ( $debug ) {
			echo "Switching to station '".$radio_config['stations'][$station_num]['name']."'...\n";
			var_dump($radio_config['stations'][$station_num]);
		}
		// check by extension if station is actually playlist
		if( !is_array($radio_config['stations'][$station_num]['URL']) ) {
			$pos = strrpos($radio_config['stations'][$station_num]['URL'], '.');
			$ext = strtolower(substr($radio_config['stations'][$station_num]['URL'], $pos+1));
			if( $ext === 'm3u' || $ext === 'pls' ) {
				if ( $debug ) {
					echo "URL is a playlist\n";
				}
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $radio_config['stations'][$station_num]['URL']);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
				curl_setopt($ch, CURLOPT_AUTOREFERER, true);
				curl_setopt($ch, CURLOPT_FAILONERROR, true);
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
				curl_setopt($ch, CURLOPT_HEADER, false);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 5);
				curl_setopt($ch, CURLOPT_TIMEOUT, 15);
				$output = curl_exec($ch);
				curl_close($ch);
				if ( $debug ) {
					echo "Fetched playlist from station (first 1K):\n";
					echo substr($output, 0, 1024)."\n";
				}
				$matches = array();
				if( $ext === 'm3u' ) {
					if ( $debug ) {
						echo "Parsing M3U format...\n";
					}
					if( preg_match_all("|^(http://.*)$|im", $output, $matches)===false ) {
						$error = "Cannot parse M3U playlist from station";
					}
				} elseif ( $ext === 'pls' ) {
					if ( $debug ) {
						echo "Parsing PLS format...\n";
					}
					if( preg_match_all("|^File\d+=(http://.*)$|im", $output, $matches)===false ) {
						$error = "Cannot parse PLS playlist from station";
					}
				}
				if ( $debug ) {
					echo "Parsed playlist from station:\n";
					var_dump($matches);
				}
				if( isset($matches[1]) ) {
					$radio_config['stations'][$station_num]['URL'] = array();
					foreach( $matches[1] as $tmp_idx => $URL ) {
						if ( $debug ) {
							echo "Add $URL to station URLs\n";
						}
						$radio_config['stations'][$station_num]['URL'][] = $URL;
					}
				} else {
					$error = "Empty playlist from station!";
				}
			}
		}

		if( !$error ) {
			if ( $debug ) {
				echo "Running switch...\n";
			}
			$return = switch_to_URL($radio_config['stations'][$station_num]['URL']);
			if ( $return!==true ) {
				$error = $return;
			}
		}
	} else {
			$error = "Station number $station_num is not found in the config";
	}
}

// interface
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="favicon.ico">
    <title><?php if( isset($radio_config['title']) ) echo $radio_config['title']; ?></title>
    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="css/custom.css" rel="stylesheet">
  </head>

  <body>
<?php

if ( $debug ) {
	echo "<pre>\n";
	echo "Stations from config:\n";
	var_dump($radio_config['stations']);
}
$current_song = "";
$output = exec_mpd_command("current");
if ( $output!==false ) {
	$current_song = $output[0];
	$current_station_num = false;
	$current_station_name = "";
	foreach( $radio_config['stations'] as $tmp_idx => $station ) {
		if ( strpos($current_song, $station['playing_prefix'] )===0 ) {
			$current_station_num = $tmp_idx;
			$current_station_name = $station['name'];
			$current_song = substr( $current_song, strlen($station['playing_prefix'])+2); // include ': ' as delimiter
		}
	}
} else {
	$error = "Cannot get current playing from mpd";
}

echo "    <a href=\"".$_SERVER['PHP_SELF']."\">";
echo "    <span class=\"glyphicon glyphicon-refresh\" aria-hidden=\"true\" title=\"Refresh\">\n";
echo "    </span></a>\n";

if ( $current_song ) {
	echo $current_song."\n";
}
echo "    <br>\n";

echo "    <form action=\"".$_SERVER['PHP_SELF']."\">\n";
echo "     <div class=\"btn-group-vertical\" role=\"group\">\n";
foreach( $radio_config['stations'] as $tmp_idx => $station ) {
	if ( $current_station_num===$tmp_idx ) {
		$color_class = "btn-primary";
	} else {
		$color_class = "";
	}
	echo "      <button type=\"submit\" class=\"btn btn-default ".$color_class."\" name=\"station_num\" value=\"".$tmp_idx."\">".$station['name']."</button>\n";
}
echo "     </div>\n";
echo "    </form>\n";

if ( $error ) {
	echo "    <br><div class=\"alert alert-danger\" role=\"alert\">".$error."</div>\n";
}

?>
    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
  </body>
</html>
