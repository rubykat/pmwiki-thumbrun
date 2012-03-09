<?php if (!defined('PmWiki')) exit();

/*	=== Thumbrun ===
 *	Copyright 2012 Kathryn Andersen <perlkat@katspace.org>
 *
 *	Thumbnail list with ImageMagick which can cope with
 *      more than just png/jpg/gif images.
 *
 *	To install, add the following line to your configuration file :
		include_once("$FarmD/cookbook/thumbrun.php");
 *
 *	For more information, please see the online documentation at
 *		http://www.pmwiki.org/wiki/Cookbook/Thumbrun
 *
 *	This program is free software; you can redistribute it and/or
 *	modify it under the terms of the GNU General Public License,
 *	Version 2, as published by the Free Software Foundation.
 *	http://www.gnu.org/copyleft/gpl.html
 *
 *      This code is partly based on Thumblist2, Mini, AutoThumber,
 *      and upload.php
 */

$RecipeInfo['Thumbrun']['Version'] = '2012-03-08';

if ( !IsEnabled($EnableUpload,0) ) return;

SDVA($Thumbrun, array(
  'ImgRx' => "/\\.(gif|png|jpe|jpe?g|wbmp|xbm|eps|svg)$/i",
  'ThumbDir' => '',
  'ThumbPrefix' => 'thumb_',
  'Px' => 128,
  'ThumbBg' => 'grey',
  'ShowUpload' => 0,
  'ShowRename' => 0,
  'ShowDelete' => 0,
));

Markup('thumbrun', 'directives', 
  '/\\(:thumbrun\\s*(.*?):\\)/ei',
  "Keep('<ul class=thumbrun>'.ThumbrunList('$pagename',PSS('$1')).'</ul>')");

function ThumbrunList($pagename, $args) {
    global $UploadDir, $UploadPrefixFmt, $UploadUrlFmt, 
           $EnableDirectDownload, $Thumbrun;

    // see if we can find ImageMagick
    $sout = shell_exec('convert -version');
    if ( strpos($sout,'ImageMagick') === FALSE ) Abort('?no ImageMagick convert command found');

    // parse the args
    $opt = ParseArgs($args);
    if (@$opt[''][0]) $pagename = MakePageName($pagename, $opt[''][0]);

    ## filter to inc/exclude files based on their name
    $filter = '';
    if (@$opt['filter'])
    {
	$filter = $opt['filter'];
    }

    $uploaddir = FmtPageName("$UploadDir$UploadPrefixFmt", $pagename);
    $uploadurl = FmtPageName(IsEnabled($EnableDirectDownload, 1) 
                             ? "$UploadUrlFmt$UploadPrefixFmt/"
                             : "\$PageUrl?action=download&amp;upname=",
                             $pagename);

    $dirp = @opendir($uploaddir);
    if (!$dirp) return '';
    $filelist = array();
    $thumb_re = '/^' . preg_quote($Thumbrun['ThumbPrefix'], '/') . '/';
    while (($file=readdir($dirp)) !== false) {
        if ($file{0} == '.') continue;
        if (@$filter && !preg_match($filter, $file)) continue;
        // match images
        if (@$Thumbrun['ImgRx'] && !preg_match($Thumbrun['ImgRx'], $file)) continue;
        // but skip thumbnails
        if (@$Thumbrun['ThumbPrefix'] && preg_match($thumb_re, $file)) continue;
        $filelist[$file] = $file;
    }
    closedir($dirp);
    $out = array();
    natcasesort($filelist);
    foreach($filelist as $file=>$x) {
        $out[] = ThumbrunListItem($pagename, $file, $uploadurl, $uploaddir,
        ($opt['px'] ? $opt['px'] : $Thumbrun['Px']));
    }
    return implode("\n",$out);
} # ThumbrunList

function ThumbrunListItem($pagename, $file, $uploadurl, $uploaddir,$px) {
    global $TimeFmt, $Thumbrun;

    $pinfo = pathinfo($file);
    $bn =  basename($file,'.'.$pinfo['extension']);
    $thumbfile = $Thumbrun['ThumbPrefix'] . ${bn} . ".png";

    $thumblink='';
    if ($Thumbrun['ThumbDir'])
    {
        $thumbdir = $Thumbrun['ThumbDir'];
        $fulldir = "$uploaddir/$thumbdir";
        if (!is_dir($fulldir))
        {
            mkdir($fulldir);
        }
        $thumblink = PUE("$uploadurl/$thumbdir/$thumbfile");
    }
    else
    {
        $thumblink = PUE("$uploadurl$thumbfile");
    }
    $link = PUE("$uploadurl/$file");
    $stat = stat("$uploaddir/$file");
    ThumbrunMakeThumb($uploaddir,$file,$thumbfile,$px,$px);
    $imginfo = "<span><a href='$link'>$file</a></span> "
        . "<span>" . number_format($stat['size']) . " bytes</span> "
        . "<span>" . strftime($TimeFmt, $stat['mtime']) . "</span>";
    $linkfmt = "<li><span class='item'><a href='%s'><img src='%s' alt='%s' title='%s'/></a> <span class='imginfo'>%s</span><span class='imgupdate'>%s</span></span></li>";
    $update = ThumbrunListItemUpdate($pagename, $file);
    $out = sprintf($linkfmt, $link, $thumblink, $file, $file, $imginfo, $update);

    return $out;
} # ThumbrunListItem

function ThumbrunListItemUpdate($pagename, $file) {
    global $EnableUploadOverwrite, $Thumbrun;

    $overwrite = '';
    if ($EnableUploadOverwrite && $Thumbrun['ShowUpload']) 
    {
        $overwrite = FmtPageName(
            "<a rel='nofollow' class='createlink'
            href='\$PageUrl?action=upload&amp;upname=$file'>&nbsp;&Delta;</a>", 
            $pagename);
    }
    $rename = '';
    if ($Thumbrun['ShowRename'])
    {
        $rename = FmtPageName(
            "<a rel='nofollow' class='createlink'
            href='\$PageUrl?action=renameattach&amp;upname=$file'>&nbsp;R</a>", 
            $pagename);
    }
    $delete = '';
    if ($Thumbrun['ShowDelete'])
    {
        $delete = FmtPageName(
            "<a rel='nofollow' class='createlink'
            href='\$PageUrl?action=delattach&amp;upname=$file'>&nbsp;X</a>", 
            $pagename);
    }
    return "$overwrite $rename $delete";
} # ThumbrunListItemUpdate

function ThumbrunMakeThumb($uploaddir,$file,$thumbfile,$w,$h) {
    global $Thumbrun;

    $filepath = "$uploaddir/$file";
    $thumbpath = "$uploaddir/$thumbfile";
    if (@$Thumbrun['ThumbDir'])
    {
        $thumbpath = $uploaddir . '/' . $Thumbrun['ThumbDir'] . '/' . $thumbfile;
    }
    if (file_exists($thumbpath) || !file_exists($filepath))
    {
        return;
    }

    $bg = $Thumbrun['ThumbBg'];
    $tmp1 = "$uploaddir/${thumbfile}_tmp.png";
    $area = $w * $h;
    #$cmdfmt = 'convert -thumbnail \'%dx%d\' -gravity center -background %s -extent \'%dx%d\' %s %s';
    
    # Need to use the following conversion instead because of
    # ImageMagick version earlier than 6.3
    $cmdfmt = 'convert -thumbnail \'%dx%d>\' -bordercolor %s -background %s -border 50 -gravity center  -crop %dx%d+0+0 +repage %s %s';
    $cl = sprintf($cmdfmt, $w, $h, $bg, $bg, $w, $h, $filepath, $tmp1);

    $r = exec($cl, $o, $status);
    if(intval($status)!=0)
    {
        Abort("convert returned <pre>$r\n".print_r($o, true)
              ."'</pre> with a status '$status'.<br/> Command line was '$cl'.");
    }

    // fluff
    #$cmdfmt = 'convert -page +4+4 %s -matte \( +clone -background navy -shadow 60x4+4+4 \) +swap -background none -mosaic %s';
    $cmdfmt = 'convert -mattecolor %s -frame 6x6+3+0 %s %s';

    $cl = sprintf($cmdfmt, $bg, $tmp1, $thumbpath);
    $r = exec($cl, $o, $status);
    if(intval($status)!=0)
    {
        Abort("convert returned <pre>$r\n".print_r($o, true)
              ."'</pre> with a status '$status'.<br/> Command line was '$cl'.");
    }
    unlink($tmp1);
} # ThumbrunMakeThumb
