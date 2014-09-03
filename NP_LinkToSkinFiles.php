<?php 
class NP_LinkToSkinFiles extends NucleusPlugin { 
	function getName() { return 'NP_LinkToSkinFiles'; }
	function getMinNucleusVersion() { return 250; }
	function getAuthor()  { return 'Katsumi + yama.kyms + yu'; }
	function getVersion() { return '0.2.7'; }
	function getURL() {return 'http://japan.nucleuscms.org/wiki/plugins:np_linktoskinfiles';}
	function getDescription() { return $this->getName().' plugin'; } 
	function supportsFeature($what) { return (int)($what=='SqlTablePrefix'); }
	function getEventList() { return array('AdminPrePageHead','AdminPrePageFoot'); }
	function install(){
		$this->createOption('linkstyle','Link style?','select','iframe','Normal|normal|New Window|newwindow|Inline Frame|iframe');
		$this->createOption('iframeheight','Hight of iframe?','text','650','datatype=numeric');
		$this->createOption('inctags','Check these tags:','textarea',"include\n"."parsedinclude\n"."phpinclude\n"."skinfile\n");
		$this->createOption('textext','Text file extensions:','textarea',"htm html txt\n"."js css inc\n"."php cgi pl xbmp\n");
	}
	var $ob_ok=false;
	function event_AdminPrePageHead(&$data){
		if ($data['action']!='skinedittype' && $data['action']!='skinupdate') return;
		$this->ob_ok=ob_start();// Begin the collection of html.
	}
	var $texts='';
	function event_AdminPrePageFoot(){
		global $CONF,$manager,$DIR_SKINS;
		if (!$this->ob_ok) return;
		$html=ob_get_contents();// End the collection of html.
		ob_end_clean();
		$skinid=intRequestVar('skinid');// $skinid is clean; it's integer.
		$type=requestVar('type');
		// Create the select form HTML
		$descs=array('index'=>_SKIN_PART_MAIN,
			'item'=>_SKIN_PART_ITEM,
			'archivelist'=>_SKIN_PART_ALIST,
			'archive'=>_SKIN_PART_ARCHIVE,
			'search'=>_SKIN_PART_SEARCH,
			'error'=>_SKIN_PART_ERROR,
			'member'=>_SKIN_PART_MEMBER,
			'imagepopup'=>_SKIN_PART_POPUP);
		$res=sql_query('SELECT stype FROM '.sql_table('skin')." WHERE sdesc=$skinid");
		$select='</form>
<form method="GET" action="">
<input type="hidden" name="skinid" value="'.$skinid.'"/>
<input type="hidden" name="type" value="'.htmlspecialchars($type).'"/>
<input type="hidden" name="action" value="skinedittype"/>
<input type="submit" style="display:none;" id="np_linktoskinfiles_select" />
<select name="type" onchange="if (this.value!=\'\') document.getElementById(\'np_linktoskinfiles_select\').click();">
<option value="">Go to</option>';
		while ($row=mysql_fetch_row($res)) {
			if (!($desc=@$descs[$row[0]])) $desc=$row[0];
			$select.='
<option value="'.htmlspecialchars($row[0]).'">'.htmlspecialchars($desc).'</option>';
		}
		$select.="</select><br /><br />\n";
		mysql_free_result($res);
		$html=str_replace(_SKIN_ALLOWEDVARS,$select._SKIN_ALLOWEDVARS,$html);
		// Prepare JavaScript
		switch($this->getOption('linkstyle')){
		case 'newwindow':
			$jscode=' onclick="window.open(this.href);return false;"';
			break;
		case 'iframe':
			$jscode=' onclick="document.getElementById(\'NP_LinkToSkinFiles_iframe\').style.display=\'block\';'.
				'document.getElementById(\'NP_LinkToSkinFiles_iframe\').scrolling=((this.href+\'\').indexOf(\'?action=editfile\')>0)?\'no\':\'auto\';'.
				'document.getElementById(\'NP_LinkToSkinFiles_iframe\').src=this.href;'.
				'return false;"';
			break;
		default:
			$jscode='';
		}
		// Inport the skin data
		$text=quickQuery('SELECT scontent as result FROM '.sql_table('skin')." WHERE sdesc=$skinid AND stype='".addslashes($type)."' LIMIT 1");// Obtain the content of skin.
		$res=sql_query('SELECT sdincmode, sdincpref FROM '.sql_table('skin_desc')." WHERE sdnumber=$skinid LIMIT 1");
		list($mode,$sdir)=mysql_fetch_row($res);
		mysql_free_result($res);
		// Create the regular expression to search tags for including files.
		// $1, $2, and $3 are the tag name, filename without extension, and file-extension, respectively.
		$textext=trim($this->getOption('textext'));
		$textext=preg_replace('/[\s]+/','|',$textext);
		$search=trim($this->getOption('inctags'));
		$search=preg_replace('/[\s]+/','|',$search);
		$search=preg_replace('/[^0-9a-zA-Z_|]+/','',$search);
		$search='/<%('.$search.')\(([^\)]+)\.('.$textext.')\)%>/';
		// Search the include tags
		$this->texts=$text;
		if (($text && $mode=='skindir' && preg_match_all($search,$text,$matches,PREG_SET_ORDER))) {
			// Create the tree of *.inc files
			$inctree=array();
			foreach($matches as $match) $inctree[$match[2].'.'.$match[3]]=$this->_seekIncFile($match[2].'.'.$match[3],$DIR_SKINS.$sdir,$search);
			// Create the links
			$skinfiles=$CONF['PluginURL'].'skinfiles/';
			$links='Edit by <a href="'.htmlspecialchars($skinfiles.'?dir='.$sdir).'"[[[__jscode__]]]>SkinFiles</a>:<br />';
			$first=true;
			foreach($inctree as $filename=>$childes) {
				if (!$first) $links.=', ';
				$first=false;
				$this->_createLinks($links,$filename,$childes,$sdir,$skinfiles);
			}
			$links=str_replace('[[[__jscode__]]]',$jscode,$links);// $jscode is clean; it does not contain the outside data.
			$links.=' [<a href="'.htmlspecialchars("?action=skinedittype&skinid=$skinid&type=$type").'">Refresh</a>]';
			$links.="<br /><br />\n";
		} else $links='';
		
		/* Insert template links */
		$tsearch='{<%([0-9a-zA-Z_-]+)\(([0-9a-zA-Z/_-]+)[^\)]*\)%>}';
		if (($this->texts && preg_match_all($tsearch,$this->texts,$tmatches,PREG_SET_ORDER))) {
			$tlist = array();

			$tdnames='';
			foreach($tmatches as $tmatch) {
				$tdname = $tmatch[2];
				/*if (strpos($tdname, '/') === false) continue;*/ 
				$tdnames.=($tdnames?',"':'"').addslashes($tdname).'"'; // $tdnames is clean
			}
			if (strlen($tdnames)){
				$query = 'SELECT tdnumber, tdname FROM '.sql_table('template_desc').' WHERE tdname in ('.$tdnames.')';
				$res = sql_query($query);
				while ($row=mysql_fetch_assoc($res)) {
					$url = 'index.php?action=templateedit&templateid=' . (int)$row['tdnumber']; //make edit link
					//$url = $manager->addTicketToUrl($url);
					$tlist[ $row['tdname'] ] = $url;
				}
			}

			if (count($tlist)) {
				if (count($tlist)==1) $links.='Template used:';
				else $links.='Templates used:';
				$links.='</a><br />';
				foreach ($tlist as $name => $url) {// $jscode is clean; it does not contain the outside data.
					$links.='<a href="'.htmlspecialchars($url).'"'.$jscode.'>'.htmlspecialchars($name).'</a>, ';
				}
				$links = substr($links, 0, -2);
				$links.= ' [<a href="'.htmlspecialchars("?action=skinedittype&skinid=$skinid&type=$type").'">Refresh</a>]';
				$links.= "<br /><br />\n";
			}
		}
		
		if (!strlen($links)) {
			echo $html;
			return;
		}
		$links.='<iframe width="100%" height="'.(int)$this->getOption('iframeheight').'" id="NP_LinkToSkinFiles_iframe" style="display:none;" onload="NP_LinkToSkinFiles_hideMenu(this);"></iframe>';
		$links.="\n";
		echo str_replace(_SKIN_ALLOWEDVARS,$links._SKIN_ALLOWEDVARS,$html);
?><script type="text/javascript">
/*<![CDATA[*/
function NP_LinkToSkinFiles_hideMenu(obj){
  var docobj=obj.contentWindow.document;
  if (!docobj.getElementById('quickmenu')) return;
  docobj.getElementById('quickmenu').style.display='none';
  docobj.getElementById('content').style.marginLeft='0px';
  docobj.getElementsByTagName('h1')[0].style.display='none';
  divobj = docobj.getElementsByTagName('div');
  for (i=0; i<divobj.length; i++) {
    if (divobj[i].attributes[0].value=='loginname') {
      divobj[i].style.display='none';
      break;
    }
  }
}
/*]]>*/
</script><?php
	}
	// Sub routines follow.  Currently, these are used for making links of *.inc files.
	function _seekIncFile($filename,$skindir,$search){
		// Check the file
		if (!file_exists($skindir.$filename)) return false;
		if (strpos(realpath($skindir.$filename),realpath($skindir))) return false;
		// Some exceptions for the ways of including
		switch(strtolower(preg_replace('/^.*\./','',$filename))){
		case 'css':
			$search='/(@import)[\s]+'.'(?:url[\s]*\([\s]*["\']?|["\'])'.'(.*?)\.(css)'.'(?:["\']?[\s]*\)|["\'])/i';
			break;
		case 'php':
			$search='/(include|require|include_once|require_once)[\s]*\([\s]*["\'](.*?)\.(php)["\'][\s]*\)/i';
			break;
		default:
			break;
		}
		// Open file
		if (!($fhandle=@fopen($skindir.$filename,'r'))) return false;
		// Check the each lines of file by regular expression
		$result=array();
		$text='';
		while ( !feof($fhandle) ) {
			$line = fgets($fhandle, 4096);
			$text.=$line;
			if ( !preg_match_all($search,$line,$incfiles,PREG_SET_ORDER) ) continue;
			foreach($incfiles as $match){
				$result[$match[2].'.'.$match[3]]=$this->_seekIncFile($match[2].'.'.$match[3],$skindir,$search);
			}
		}
		fclose($fhandle);
		$this->texts.=$text;
		return count($result)?$result:false;
	}
	function _createLinks(&$links,$filename,$childes,$sdir,$skinfiles){
		// $links will be directry echoed, so be careful and think about XSS.
		global $DIR_SKINS,$manager,$CONF;
		if (file_exists($DIR_SKINS.$sdir.$filename)) {
			// Link to SkinFiles
			$url=$skinfiles.'?action=editfile';
			$url.='&file='.$sdir.$filename;
			$url=$manager->addTicketToUrl($url);
		} else {
			// Link to action of this plugin
			$url=$CONF['ActionURL'];
			$url.='?action=plugin&name=LinkToSkinFiles&type=createfile';
			$url.='&file='.$sdir.$filename;
		}
		$links.='<a href="'.htmlspecialchars($url).'"'.
			'[[[__jscode__]]]>'.htmlspecialchars($filename).'</a>';
		if ($childes) {
			$links.='(';
			$first=true;
			foreach($childes as $childname=>$grandchildes) {
				if (!$first) $links.=', ';
				$first=false;
				$this->_createLinks($links,$childname,$grandchildes,$sdir,$skinfiles);
			}
			$links.=')';
		}
	}
	// doAction is used for creating form to confirm making a new file.
	function doAction($type){
		global $manager,$member,$DIR_SKINS,$CONF;
		// Allow only super-admin to do this.
		if (!($member->isLoggedIn() && $member->isAdmin())) return _ERROR_DISALLOWED;
		switch($type=strtolower($type)){
		case 'createfile':
			$skinfiles=$CONF['PluginURL'].'skinfiles/';
			if (!($file=getVar('file'))) return _ERROR_DISALLOWED;
			$fullpath=$DIR_SKINS.$file;
			// Check if the fullpath is within DIR_SKINS
			$tempdir=$fullpath;
			while(preg_match('![^\/][\/]!',$tempdir)){
				if (file_exists($tempdir=dirname($tempdir))) break;
			}
			if (strpos(realpath($tempdir),realpath($DIR_SKINS))!==0) {
				// Following test occurs when $tempdir is $DIR_SKINS but the last '/' lacks.
				if (strpos(realpath($tempdir.'/'),realpath($DIR_SKINS))!==0) return _ERROR_DISALLOWED;
			}
			$url=$skinfiles.'?action=editfile';
			$url.='&file='.htmlspecialchars($file,ENT_QUOTES);
			$url=$manager->addTicketToUrl($url);
			if (file_exists($fullpath)) {
				// Redirect to SkinFiles if the file exists.
				redirect($url);
				exit;
			}
			if ($manager->checkTicket() && requestVar('ticket')==postVar('ticket')) {
				// POST mode is used to create the file really.
				// Create a file and redirect to SkinFiles
				if (postVar('createdir')=='yes') {
					// Create the directory
					$count=0;
					while(!file_exists(dirname($fullpath))) {
						if (10==$count++) return 'Cannot create the directory.';
						$tempdir=dirname($fullpath);
						while(preg_match('![^\/][\/]!',$tempdir)){
							if (realpath(dirname($tempdir))) break;
							$tempdir=dirname($tempdir);
						}
						@mkdir($tempdir,0755);
					}
				}
				if (!@fclose(@fopen($fullpath,'x'))) return 'Cannot create the file (maybe, the directory does not exist).';
				redirect($url);
				exit;
			}
			// There is no valid ticket. Let's show the form button to create file.
			header('Content-type: text/html; charset='._CHARSET);
?><html><head><title>Create a skin file</title>
<meta http-equiv="Content-Type" content="text/html; charset=<?php echo _CHARSET;?>" />
</head><body><form method="POST" action="">
Create the file, "<span style="color:red;"><?php echo htmlspecialchars($file); ?></span>" in skins directory?<br /><br />
<?php $manager->addTicketHidden(); ?>
<?php if (!file_exists(dirname($fullpath))) echo '<input type="checkbox" name="createdir" value="yes" />Check if create a new directory.<br /><br />'; ?>
<input type="submit" value="<?php echo _YES; ?>" />
<input type="button" value="<?php echo _NO; ?>" onclick="
document.location='<?php echo htmlspecialchars($skinfiles.'?dir='.dirname($file).'/',ENT_QUOTES); ?>';
return false;"/>
</form></body></html><?php
			exit;
		default:
			return _ERROR_DISALLOWED;
		}
	}
}
