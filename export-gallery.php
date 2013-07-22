<?php
/*
 * Gallery 2 to Flickr Import Script
 * 
 * The code in THIS FILE ONLY is public domain
 * (Released Nov 3, 2006 by Taj Morton <tajmorton@gmail.com>
 * All other files have a copyright notice and license information
 * in them.
 *
 * General Disclaimer:
 * This script comes with NO WARRANTY or ANYTHING LIKE IT. I am NOT RESPONSIBLE for
 * anything it does. You get to keep all the pieces.
 *
 * To use this script, you must set (or confirm to be correct) 
 * by editing config_blank.php. Pass the file as an argument when you are ready.
 * DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_DB,
 * DATABASE_TABLE_PREFIX, DATABASE_COLUMN_PREFIX, FLICKR_API_KEY,
 * FLICKR_SECRET, and BASE_DIRECTORY.
 * The DATABASE_* and BASE_DIRECTORY settings can be found in config.php,
 * located in the top directory of your Gallery 2 install.
 *
 * FLICKR_API_KEY and FLICKR_SECRET must be aquired from Flickr. You can get
 * them from this website: http://www.flickr.com/services/api/keys/
 * 
 * You need to set the Flickr "Callback URL" to <url>/auth.php.
 * <url> is the URL where you will upload the contents of the folder on
 * your server.
 *
 * After setting these defines, upload this folder your webserver open
 * export-gallery.php in your browser. Make sure you have cookies enabled,
 * otherwise Flickr won't be able to authorize this script to access your
 * account.
 *
 * When I imported my gallery, this script got killed a few times (either because Flickr
 * stopped talking to it, my webhost killed it because it ran too long, or something else).
 * After cleaning up after it (deleting all the photos that it had uploaded that were not
 * in a set), I changed the first MySQL query to include:
 * AND i.".DATABASE_COLUMN_PREFIX."id > last_sucessful_album_id
 * after ...DATABASE_COLUMN_PREFIX."canContainChildren=1
 * This forced the script to start at the beginning of the albun where it left off.
 * Like I said, I don't really know why it died--you may have better luck than me.
 *
 * If you have any improvents, please send them to me so I can add them!
 * Taj Morton -- tajmorton@gmail.com -- Nov 3, 2006
 */

// ------------- You shouldn't need to modify anything below this line: -------------

header("Content-type: text/html");

// Read configuration file
if ( 1 >= $argc ) {
		echo "\nPlease pass location of a config.php\n";
		exit(1);
} else {
		require_once("$argv[1]");
		if ( ! defined('DATABASE_HOST') ) {
				echo "\nPlease pass location of a config.php\n";
				exit(1);
		}
}

// Dry-run (TODO use getopt() to specify via command-line
$dryrun = true;

require_once("phpFlickr.php");

$f = new phpFlickr(API_KEY, SECRET);
$f->setToken(TOKEN);

$fes = new phpFlickr(API_KEY, SECRET);
$fes->setToken(TOKEN);

// need to have 2 phpFlickr objects because for some reason
// after uploading, phpFlickr can't deal with creating/adding photos
// to sets:

$link = mysql_connect(DATABASE_HOST,DATABASE_USER,DATABASE_PASS);
if (!$link) {
		die("Error: Couldn't connect to database server. MySQL said: ".mysql_error());
}

$db = mysql_select_db(DATABASE_DB,$link);

if (!$db) {
		die("Error: Couldn't select the database. MySQL said: ".mysql_error());
}


// skip NULL pathComponents because it probably means that we've either found a really messed
// up album that I'm not going to deal with, or that we've found the "Gallery" album
$query = "SELECT i.".DATABASE_COLUMN_PREFIX."title, 
		i.".DATABASE_COLUMN_PREFIX."id, i.".DATABASE_COLUMN_PREFIX."description, 
		fse.".DATABASE_COLUMN_PREFIX."pathComponent FROM ".DATABASE_TABLE_PREFIX."Item i INNER JOIN 
		".DATABASE_TABLE_PREFIX."FileSystemEntity fse ON i.".DATABASE_COLUMN_PREFIX."id = 
		fse.".DATABASE_COLUMN_PREFIX."id WHERE i.".DATABASE_COLUMN_PREFIX."canContainChildren=1 AND 
		i.".DATABASE_COLUMN_PREFIX."ownerId=".GALLERY_OWNER." AND 
		fse.".DATABASE_COLUMN_PREFIX."pathComponent IS NOT NULL ORDER BY i.".DATABASE_COLUMN_PREFIX."id";
		//echo $query;
		$result = mysql_query($query);
		echo "<ul>";


		while ($row = mysql_fetch_assoc($result)) {
				$query = "SELECT * FROM ".DATABASE_TABLE_PREFIX."ChildEntity i INNER JOIN " .DATABASE_TABLE_PREFIX."PhotoItem fse ON i." .DATABASE_COLUMN_PREFIX."id = fse.".DATABASE_COLUMN_PREFIX."id where i.g_parentId = ".$row[DATABASE_COLUMN_PREFIX."id"];
				//echo "\n$query\n";
				$result_temp = mysql_query($query) or print(mysql_error());
				if (mysql_num_rows($result_temp)==0) continue;
				//  if ($row[DATABASE_COLUMN_PREFIX."id"] < 94950) continue; //used to continue the script from a specific album after it prematurely ends

				echo "<li>Album Title: ".$row[DATABASE_COLUMN_PREFIX."title"]."</li>";
				echo "<li>Album Id: ".$row[DATABASE_COLUMN_PREFIX."id"]."</li>";
				echo "<li>Album Path: ".$row[DATABASE_COLUMN_PREFIX."pathComponent"]."</li>";
				echo "<li>Description: ".$row[DATABASE_COLUMN_PREFIX."description"]."<br/>";
				$uploadedPics=array();

				$query = "SELECT i.".DATABASE_COLUMN_PREFIX."id, i.".DATABASE_COLUMN_PREFIX."title, i.".DATABASE_COLUMN_PREFIX."description, fse.".DATABASE_COLUMN_PREFIX."pathComponent 
						FROM ".DATABASE_TABLE_PREFIX."Item i 
						INNER JOIN ".DATABASE_TABLE_PREFIX."ChildEntity ce ON i.".DATABASE_COLUMN_PREFIX."id = ce.".DATABASE_COLUMN_PREFIX."id 
						INNER JOIN ".DATABASE_TABLE_PREFIX."FileSystemEntity fse ON i.".DATABASE_COLUMN_PREFIX."id = fse.".DATABASE_COLUMN_PREFIX."id 
						INNER JOIN ".DATABASE_TABLE_PREFIX."PhotoItem gse ON i.".DATABASE_COLUMN_PREFIX."id = gse.".DATABASE_COLUMN_PREFIX."id 
						WHERE ce.".DATABASE_COLUMN_PREFIX."parentId=".$row[DATABASE_COLUMN_PREFIX."id"].
						" ORDER BY i.".DATABASE_COLUMN_PREFIX."id";
				$childern = mysql_query($query);
				echo "<ul>";
				while ($child = mysql_fetch_assoc($childern)) {
						$query = "SELECT i.".DATABASE_COLUMN_PREFIX."parentSequence FROM ".DATABASE_TABLE_PREFIX."ItemAttributesMap i WHERE i.".DATABASE_COLUMN_PREFIX."itemId =".$child[DATABASE_COLUMN_PREFIX."id"];
						$result_temp = mysql_query($query);
						$temp = mysql_fetch_array($result_temp);
						$path = BASE_DIRECTORY."/albums/".fullpath($temp[0],$child[DATABASE_COLUMN_PREFIX."id"]);
						echo "<li>".$child[DATABASE_COLUMN_PREFIX."title"]." -- ".$child[DATABASE_COLUMN_PREFIX."pathComponent"]."<br/>".$child[DATABASE_COLUMN_PREFIX."description"]." File is ".$path."</li>";
						//	continue;

						if ( ! $dryrun ) {
								$uploadedPics[]=$f->sync_upload(
												html_entity_decode($path),
												html_entity_decode($child[DATABASE_COLUMN_PREFIX."title"]),
												html_entity_decode($child[DATABASE_COLUMN_PREFIX."description"]),
												null, // tags
												true); // public 
						} else {
							echo "\nuploading $path\n";
						}

						if (count($uploadedPics)%8) {
								// every 8 photos, give flickr a break and a chance
								// to catch up. Adjust this value if you need to.
								// Without this sleep, the connection dropped a lot
								// and I had to keep reuploading the same photos
								sleep(3);
						}
				}
				//	sleep(2);
				if ( ! $dryrun )	
						$setid=$fes->photosets_create(html_entity_decode($row[DATABASE_COLUMN_PREFIX."title"]),html_entity_decode($row[DATABASE_COLUMN_PREFIX."description"]),$uploadedPics[0]);
				else
						echo "\nCreating set ".html_entity_decode($row[DATABASE_COLUMN_PREFIX."title"])."\n"; 
				if ( ! $dryrun ) {
						foreach($uploadedPics as $pid) {
								echo "\nAdding $pid<br/>";
								$fes->photosets_addPhoto($setid['id'],$pid);
						}
						echo "</li></ul>";
				} else {
						echo "\nAdding $path to setid ".$setid['id']."\n";
				}
				//	sleep(3); // take a good fitful sleep after uploading a whole album
		}
echo "</ul>";

function fullpath($parents,$id){
		$pieces = explode("/", $parents);
		$full_path = "";
		foreach($pieces as $parent){
				if($parent == "") continue;
				$query = "SELECT i.".DATABASE_COLUMN_PREFIX."pathComponent FROM ".DATABASE_TABLE_PREFIX."FileSystemEntity i WHERE i.".DATABASE_COLUMN_PREFIX."id =".$parent;
				$result_temp = mysql_query($query);
				$temp = mysql_fetch_array($result_temp);
				$full_path = $full_path . "/" . $temp[0];
		}
		$query = "SELECT i.".DATABASE_COLUMN_PREFIX."pathComponent FROM ".DATABASE_TABLE_PREFIX."FileSystemEntity i WHERE i.".DATABASE_COLUMN_PREFIX."id =".$id;
		$result_temp = mysql_query($query);
		$temp = mysql_fetch_array($result_temp);
		$full_path = $full_path . "/" . $temp[0];
		return $full_path;
}

mysql_close($link);



?>
