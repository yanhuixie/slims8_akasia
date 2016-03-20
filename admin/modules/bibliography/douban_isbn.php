<?php
/* Douban API ISBN Web Services section */

// key to authenticate
define('INDEX_AUTH', '1');
// key to get full database access
define('DB_ACCESS', 'fa');

if (!isset ($errors)) {
    $errors = false;
}

// start the session
require '../../../sysconfig.inc.php';
// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
require SB.'admin/default/session.inc.php';
require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}

$zserver = 'https://api.douban.com/v2/book/isbn/:';

/* RECORD OPERATION */
if (isset($_POST['saveD']) AND isset($_SESSION['douban_result'])) {
  require MDLBS.'bibliography/biblio_utils.inc.php';

  $gmd_cache = array();
  $publ_cache = array();
  $place_cache = array();
  $lang_cache = array();
  $author_cache = array();
  $subject_cache = array();
  $input_date = date('Y-m-d H:i:s');
  // create dbop object
  $sql_op = new simbio_dbop($dbs);
  $r = 0;

  foreach ($_POST['drecord'] as $id) {
      // get record detail
      $record = $_SESSION['douban_result'][$id];
      // insert record to database
      if ($record) {
          // create dbop object
          $sql_op = new simbio_dbop($dbs);
          $biblio['title']        = $dbs->escape_string(trim($record['title'].' '.$record['subtitle'].' '.$record['origin_title']));
          $biblio['isbn_issn']    = $dbs->escape_string(trim(empty($record['isbn13']) ? $record['isbn10'] : $record['isbn13']));
          $biblio['publish_year'] = $dbs->escape_string(trim($record['pubdate']));
          $biblio['collation']    = $dbs->escape_string(trim($record['binding'].' '.$record['pages']));
          $biblio['series_title'] = $dbs->escape_string(trim($record['series']['title']));
          $biblio['notes']        = $dbs->escape_string(trim($record['summary']));
          $biblio['image']        = $dbs->escape_string(trim(isset($record['images']['large']) ? $record['images']['large'] : $record['image']));
          $biblio['language_id']  = '';
          
          // download image
          $rtn = utility::downloadImage($biblio['image'], IMGBS.'docs');
          if(isset($rtn['file_name'])){
              $biblio['image'] = $rtn['file_name'];
          }
          
          // gmd
          //$biblio['gmd_id'] = utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', $record['gmd'], $gmd_cache);
          //unset($biblio['gmd']);
          // publisher
          $biblio['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $record['publisher'], $publ_cache);
          unset($biblio['publisher']);
          // publish place
          //$biblio['publish_place_id'] = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $record['publish_place'], $place_cache);
          //unset($biblio['publish_place']);
          // language
          //$biblio['language_id'] = utility::getID($dbs, 'mst_language', 'language_id', 'language_name', $record['language']['name'], $lang_cache);
          //unset($biblio['language']);
          // authors
          $authors = array();
          if (isset($record['authors'])) {
              $authors = $record['authors'];
              unset($biblio['authors']);
          }
          // subject
          //$subjects = array();
          //if (isset($record['subjects'])) {
          //    $subjects = $record['subjects'];
          //    unset($biblio['subjects']);
          //}

          $biblio['input_date'] = date('Y-m-d H:i:s'); //$biblio['create_date'];
          // $biblio['last_update'] = $biblio['modified_date'];
          $biblio['last_update'] = date('Y-m-d H:i:s');

          // remove unneeded elements
          unset($biblio['manuscript']);
          unset($biblio['collection']);
          unset($biblio['resource_type']);
          unset($biblio['genre_authority']);
          unset($biblio['genre']);
          unset($biblio['issuance']);
          unset($biblio['location']);
          unset($biblio['id']);
          unset($biblio['create_date']);
          unset($biblio['modified_date']);
          unset($biblio['origin']);

          // insert biblio data
          $sql_op->insert('biblio', $biblio);
          echo '<p>'.$sql_op->error.'</p><p>&nbsp;</p>';
          $biblio_id = $sql_op->insert_id;
          if ($biblio_id < 1) {
              continue;
          }
          // insert authors
          if ($authors) {
              $author_id = 0;
              foreach ($authors as $author) {
                  $author_id = getAuthorID($author['name'], strtolower(substr($author['author_type'], 0, 1)), $author_cache);
                  @$dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblio_id, $author_id, ".$author['level'].")");
              }
          }
          // insert subject/topical terms
          if ($subjects) {
              foreach ($subjects as $subject) {
                  if ($subject['term_type'] == 'Temporal') {
                      $subject_type = 'tm';
                  } else if ($subject['term_type'] == 'Genre') {
                      $subject_type = 'gr';
                  } else if ($subject['term_type'] == 'Occupation') {
                      $subject_type = 'oc';
                  } else {
                      $subject_type = strtolower(substr($subject['term_type'], 0, 1));
                  }
                  $subject_id = getSubjectID($subject['term'], $subject_type, $subject_cache);
                  @$dbs->query("INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES ($biblio_id, $subject_id, 1)");
              }
          }
          if ($biblio_id) {
              // write to logs
              utility::writeLogs($dbs, 'staff', $_SESSION['uid'], 'bibliography', $_SESSION['realname'].' insert bibliographic data from P2P service (server:'.$p2pserver.') with ('.$biblio['title'].') and biblio_id ('.$biblio_id.')');
              $r++;
          }
      }
  }

  // destroy result Z3950 session
  unset($_SESSION['douban_result']);
  utility::jsAlert($r.' records inserted to database.');
  echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
  exit();
}
/* RECORD OPERATION END */

/* SEARCH OPERATION */
if (isset($_GET['keywords']) AND $can_read) {
  require LIB.'modsxmlslims.inc.php';
  $_SESSION['douban_result'] = array();
  $keywords = urlencode(trim($_GET['keywords']));

  $query = '';
  if ($keywords) {
    $sru_server = $zserver . $keywords;
    $result = utility::curl_request($sru_server);
    $robj = json_decode($result, TRUE);    //var_dump($result);die;
    
    $hits = 0;
    if(NULL === $robj){
        echo '<div class="errorBox">Connect to Douban Server Failed!</div>';
    }
    else{
        $hits = isset($robj['msg']) ? 0 : 1;
    }
    
    if ($hits > 0) {
      echo '<div class="infoBox">Found '.$hits.' records from Douban API Server.</div>';
      echo '<form method="post" class="notAJAX" action="'.MWB.'bibliography/douban_isbn.php" target="blindSubmit">';
      echo '<table align="center" id="dataList" cellpadding="5" cellspacing="0">';
      echo '<tr>';
      echo '<td colspan="3"><input type="submit" name="saveD" value="'.__('Save Douban Book Records to Database').'" /></td>';
      echo '</tr>';
      $row = 1;
        // authors
        $authors = array(); 
        foreach ($robj['author'] as $auth) { 
            $authors[] = ['name'=>$auth, 'author_type'=>'p', 'level'=>'1']; 
        }
        foreach ($robj['translator'] as $auth) { 
            $authors[] = ['name'=>$auth, 'author_type'=>'p', 'level'=>'4']; 
        }
        
        $robj['authors'] = $authors;
        // save it to session vars for retrieving later
        $_SESSION['douban_result'][$row] = $robj;
        
        $row_class = ($row%2 == 0)?'alterCell':'alterCell2';
        echo '<tr>';
        echo '<td width="1%" class="'.$row_class.'"><input type="checkbox" name="drecord['.$row.']" value="'.$row.'" /></td>';
        echo '<td width="80%" class="'.$row_class.'"><strong>'.$robj['title'].'</strong>'.
            '<div><i>'.(empty($authors) ? '' : implode(' - ', $authors[0])).'</i></div></td>';
        if (isset ($robj['isbn13'])) {
            echo '<td width="19%" class="'.$row_class.'">'.$robj['isbn13'].'</td>';
        } else {
            echo '<td width="19%" class="'.$row_class.'">'.$robj['isbn10'].'</td>';
        }
        echo '</tr>';

      echo '</table>';
      echo '</form>';
    } 
    else if ($errors) {
      echo '<div class="errorBox"><ul>';
      foreach ($errors as $errmsg) {
          echo '<li>'.$errmsg.'</li>';
      }
      echo '</ul></div>';
    } else {
      echo '<div class="errorBox">No Results Found!</div>';
    }
  } else {
    echo '<div class="errorBox">No Keywords Supplied!</div>';
  }
  exit();
}
/* SEARCH OPERATION END */

/* search form */
?>
<fieldset class="menuBox">
<div class="menuBoxInner biblioIcon">
	<div class="per_title">
	    <h2><?php echo __('Douban ISBN Search/Retrieve via URL'); ?></h2>
    </div>
    <div class="sub_section">
    <form name="search" id="search" action="<?php echo MWB; ?>bibliography/douban_isbn.php" 
          loadcontainer="searchResult" method="get" style="display: inline;"><?php echo __('Search'); ?> :
        <input type="text" name="keywords" id="keywords" size="30" />
    <select name="index">
        <option value="bath.isbn"><?php echo __('ISBN/ISSN'); ?></option>
    </select>
    <input type="submit" id="doSearch" value="<?php echo __('Search'); ?>" class="btn btn-default" />
    </form>
    </div>
    <div class="infoBox"><?php echo __('* Please make sure you have a working Internet connection.'); ?></div>
</div>
</fieldset>
<div id="searchResult">&nbsp;</div>
