<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);

$groupswithaccess = 'RADIONER,CEO,FOUNDER';

require_once '/slpw/sitelokpw.php';
require_once '/slpw/slconfig.php';

require_once '/getID3/getid3/getid3.php';
require_once '/getID3/getid3/write.php';

date_default_timezone_set('US/Eastern');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in_title = isset($_POST['title']) ? $_POST['title'] : '';
  $in_artist = isset($_POST['artist']) ? $_POST['artist'] : '';
  $in_album = isset($_POST['album']) ? $_POST['album'] : '';
  $in_year = isset($_POST['year']) ? $_POST['year'] : '';
  $in_track = isset($_POST['track']) ? $_POST['track'] : '';
  $in_genre = isset($_POST['genre']) ? $_POST['genre'] : '';
  $in_record = isset($_POST['record-label']) ? $_POST['record-label'] : '';
  $in_copyright = isset($_POST['copyright']) ? $_POST['copyright'] : '';

  $title = $con->real_escape_string($in_title);
  $artist = $con->real_escape_string($in_artist);
  $album = $con->real_escape_string($in_album);
  $genre = $con->real_escape_string($in_genre);
  $record = $con->real_escape_string($in_record);
  $copyright = $con->real_escape_string($in_copyright);
  $copyright_message = '';

  // Validation
  preg_match('/^tz[1-3]([a-z0-9]{33})$/i', $slcustom6, $matches);
  if (!isset($matches[1])) {
    http_response_code(400);
    die('Invalid tz address');
  }

  $check_query = "SELECT * FROM `song` WHERE `SONG_NAME`='$title' AND `ARTIST_NAME`='$artist'";
  $check_res = $con->query($check_query);
  if ($check_res->num_rows > 0) {
    $result = $check_res->fetch_object();
    $username = $result->USER_NAME;
    $country_code = $result->COUNTRY_CODE;
    $year = substr($result->YEAR, -2);
    $number_id = $result->NUMBER_ID;
    $rdon_id = $country_code . 'RADION' . $year . $number_id;

    if ($username !== $slusername) {
      // potential copyright infringement
      // flag both users
      $user1 = $con->real_escape_string($username);
      $user2 = $con->real_escape_string($slusername);
      $query = "INSERT INTO `copyright_infringement` (`RDON_ID`,`USER_1`,`USER_2`) VALUES ('$rdon_id','$user1','$user2')";
      $con->query($query);
      die('Potential copyright infringement detected');
    }
  }

  $licenses = ['by', 'by-nc', 'by-nd', 'by-nc-nd', 'by-sa', 'by-nc-sa'];
  if (in_array($copyright, $licenses)) {
    $patterns = array('/by/', '/nc/', '/nd/', '/sa/');
    $replace = array('Attribution', 'NonCommercial', 'NoDerivatives', 'ShareAlike');
    $copyright_message = preg_replace($patterns, $replace, $copyright);
  } else die('Unknown license');

  if (isset($_SESSION) && isset($_SESSION['ses_sluserid'])) {
    // uses gmdate() (timezone+00:00) when inserting a song to the database
    $date = gmdate('Y-m-d H:i:s');
    $year = gmdate('Y');
    $nid_res = $con->query("SELECT `NUMBER_ID` FROM `song` WHERE `YEAR`=$year AND `COUNTRY_CODE`='$slcustom1' ORDER BY `SONG_ID` DESC");
    $number_id = $nid_res->num_rows > 0 ? (int) $nid_res->fetch_object()->NUMBER_ID : -1;
    $number_id = (string) $number_id + 1;
    while (strlen($number_id) < 5) $number_id = "0$number_id";
    $rdon_id = $slcustom1 . 'RADION' . substr($year, -2) . $number_id;
    $insert_query = "INSERT INTO `song` (`COUNTRY_CODE`,`RDON_ID`,`NUMBER_ID`,`USER_NAME`,`SONG_NAME`,`SONG_GENRE`,`ARTIST_NAME`,`ALBUM_NAME`,`YEAR`,`RECORD_LABEL`,`COPYRIGHT`,`SONG_SUBMIT_DATE`) VALUES ('$slcustom1','$rdon_id','$number_id','$slusername','$title','$genre','$artist','$album',$year,'$record','$copyright','$date')";

    if ($con->query($insert_query)) {
      $last_inserted_id = $con->insert_id;
      $mp3moved = false;
      $imgmoved = false;
      $imgpath = '';
      $imgext = '';

      $folderpath = 'slbackups_mlicqx83sq/Music';
      $mp3ext = pathinfo($_POST['mp3'], PATHINFO_EXTENSION);

      if (isset($_FILES['artwork'])) {
        // Move uploaded artwork file if it was set
        $imginfo = pathinfo($_FILES['artwork']['name']);
        $imgext = $imginfo['extension'];
        $imgpath = "$folderpath/$rdon_id.$imgext";
        $imgmoved = move_uploaded_file($_FILES['artwork']['tmp_name'], $imgpath);
      } else $imgmoved = true;

      // Move uploaded mp3 file to slbackups_mlicqx83sq/Music and wait for votes
      $mp3path = "$folderpath/$rdon_id.$mp3ext";
      $mp3moved = rename('uploads/' . $_POST['mp3'], $mp3path);

      // make sure MP3 and artwork was moved
      if ($mp3moved && $imgmoved) {
        $getid3 = new getID3;
        $getid3->setOption(array(
          'encoding' => 'UTF-8'
        ));

        // Set ID3 tag data
        $copyright_upper = strtoupper($copyright);
        $tag_data = [
          'title' => array($in_title),
          'artist' => array($in_artist),
          'album' => array($rdon_id),
          'year' => array($in_year),
          'track_number' => array($in_track),
          'genre' => array($in_genre),
          'comment' => array($slcustom6),
          'publisher' => array('RADION'),
          'url_publisher' => array('https://www.radion.fm'),
          'copyright_message' => array("CC $copyright_upper"),
          'copyright' => array("https://creativecommons.org/licenses/$copyright/4.0/")
        ];

        if ($imgpath !== '') {
          // Set ID3 artwork tag if it was set
          $image_data = file_get_contents($imgpath);
          $tag_data['attached_picture'] = array(
            array(
              'data' => $image_data,
              'picturetypeid' => '3',
              'description' => 'artwork',
              'mime' => preg_match('/^(jpe?g)$/i', $imgext) ? 'image/jpeg' : 'image/png'
            )
          );
        }

        $tagwriter = new getid3_writetags;
        $tagwriter->filename = $mp3path;
        // Write to MP3 with formats ID3v1 and ID3v2.3
        $tagwriter->tagformats = ['id3v1', 'id3v2.3'];
        $tagwriter->tag_encoding = 'UTF-8';
        $tagwriter->overwrite_tags = true;
        $tagwriter->remove_other_tags = true;
        $tagwriter->tag_data = $tag_data;

        if ($tagwriter->WriteTags()) {
          header('Content-Type: application/json');
          $result = json_encode([
            'id' => $rdon_id,
            'hash' => md5("asset-preview-$rdon_id")
          ]);

          die($result);
        } else {
          echo 'error7:';
          print_r($tagwriter->warnings);
          print_r($tagwriter->errors);
        }
      } else {
        $con->query("DELETE FROM `song` WHERE `SONG_ID`=$last_inserted_id");
        echo 'error5';
      }
    } else echo 'error2: ' . $con->error;
  } else echo 'error3';
} else echo 'error4';

?>
