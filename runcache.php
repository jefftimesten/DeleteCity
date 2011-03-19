#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");
require_once("Video.class.php");
require_once("pid.class.php");
libxml_use_internal_errors(true);


$start_time = time();
print "Status: Cache starting at " . date("F j, Y, g:i a") . "\n\n";



/*******************************
*
*	SANITY CHECKS
*
*******************************/

$pid = new pid( dirname(__FILE__) );
if($pid->already_running)
{
	print "Error: runcache is already running.\n";
	exit;
}

if(!file_exists($youtube_dl))
{
	print "Error: $youtube_dl not found";
	exit;
}

if(!is_writable($cache_dir))
{
	print "Error: Cache directory is not writable.\n";
	exit;
}

// Load in the URLs from the 'sources' file
if(!$urls = file($sources_file, FILE_SKIP_EMPTY_LINES))
{
	print "Error: Couldn't open $sources_file\n";
	exit;
}


/*******************************
*
*	CACHING PROCESS
*
*******************************/


// Loop through all of the URLs from the 'sources' file
foreach($urls as $url)
{
	if(strpos($url, "http://gdata.youtube.com/feeds/api/")!=0)
	{
		print "Error: Must be a gdata.youtube.com/feeds/api feed\n";
		continue;
	}

	print "Status: Fetching $url\n";

	// Download feed
	$response = get_web_page( $url );
	if($response['errno'] >0)
	{
		print "Error: {$response['errmsg']}\n";
		continue;
	}
	
	// Try to parse feed
	$xmlstr = $response['content'];
	$xmldoc = simplexml_load_string($xmlstr);
	if (!$xmldoc)
	{
		$errors = libxml_get_errors();
		foreach ($errors as $error)
		{
			echo display_xml_error($error, $xml);
		}
		libxml_clear_errors();
		continue;
	}

	if(!isset($xmldoc->entry) || count($xmldoc->entry)==0)
	{
		print "Error: Feed doesn't contain any entries\n";
		continue;
	}
	
	// TO DO:  Check to make sure this is a valid YouTube feed
	// maybe some XPaths to make sure the status is OK and that it has entries, etc.
	
	$num_vids = count($xmldoc->entry);
	$i=1;
	foreach($xmldoc->entry as $entry)
	{
		$vid_url = $entry->link[0]['href'];				// TO DO:  We can't be sure that the href is element 0
		preg_match("/v=([^&]+)/", $vid_url, $matches);

		$video = new Video( $matches[1] );
		
		// If we need to download the video, do it!
		if(!file_exists($video->vid_path))
		{
			print "\tStatus: [$i/$num_vids] Downloading \"{$entry->title}\" ({$video->youtube_id})\n";
			
			// http://rg3.github.com/youtube-dl/documentation.html#d6
			`$youtube_dl --continue --no-overwrites --ignore-errors --format=18 --output="{$cache_dir}/%(id)s.%(ext)s" --rate-limit=$rate_limit $vid_url`;
		}
		
		// If the video hasn't been saved to the database, save it!
		if(!$video->in_db)
		{
			if(file_exists($video->vid_path))
			{
				print "\tStatus: [$i/$num_vids] Adding \"{$entry->title}\" ({$video->youtube_id}) to database\n";
				$video->title = $entry->title;
				$video->author = $entry->author->name;
				$video->save();
			}
			else
			{
				print "\tError: [$i/$num_vids] File was not successfully downloaded.  Not adding to database.\n";
			}
		}
		$i++;
	}
}



/*******************************
*
*	CHECK FOR LOCAL ORPHANED VIDEOS
*	downloaded videos that don't have an entry in the database
*
*******************************/

print "Status: Checking for orphans\n";
$dhandle = opendir($cache_dir);
if ($dhandle)
{
	while (false !== ($fname = readdir($dhandle)))
	{
		if ($fname!='.' && $fname!='..' && !is_dir("./$fname") && !strpos($fname,".part") && $fname!="README")
		{
			$youtube_id = strstr(basename($fname), ".", true);
			if(!empty($youtube_id))
			{
				$video = new Video( $youtube_id );
				if(!$video->in_db)
				{
					if($video->fetch_info()) 
					{
						print "\tStatus: Inserting an orphaned video file: $youtube_id.\n";
						$video->save();
					}
					else 
					{
						print "\tError: Couldn't fetch info for $youtube_id.  Skipping\n";
					}
				}
			}
		}
	}
	closedir($dhandle);
}



/*******************************
*
*	CHECK FOR DELETED VIDEOS
*
*******************************/

// Now loop through every video where removed=0 and check to see if it still exists on YouTube
print "Status: Checking for removed videos\n";

$result = $db->query("SELECT youtube_id FROM videos WHERE removed=0", SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Error: Impossible to execute query.");


$total = $result->numRows();
$i=1;
while($row = $result->fetch(SQLITE_ASSOC))
{ 
	$video = new Video( $row['youtube_id'] );

	if($video->check_remote())
	{
		print "\tStatus: [$i/$total] {$row['youtube_id']} still exists.  Age={$video->age}\n";
		$video->mark_as_updated();
		
		if($video->age > $max_age)
		{
			$video->delete();
		}
	}
    else
    {
    	print "\tStatus: [$i/$total] {$row['youtube_id']} has been removed!\n";
    	$video->mark_as_removed();
    } 
    $i++;
}



/*******************************
*
*	OUTPUT
*
*******************************/
print "Status: Ouputting";

$sql = "SELECT id, youtube_id, title, author, date_added, date_updated, removed,
	round(strftime('%J', datetime('now'))-strftime('%J', date_added), 2) as age
	FROM videos WHERE removed > 0";

$result = $db->query($sql, SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Impossible to execute query.");

print $result->numRows()." results\n";

while ($row = $result->fetch(SQLITE_ASSOC))
{ 
    //print_r($row); 
}




/*******************************
*
*	DONE
*
*******************************/

$elapsed_time = time() - $start_time;
$minutes = $elapsed_time / 60.0;
print "Status: elapsed time: {$minutes} minutes\n\n";
?>