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

$RecipeInfo['Thumbrun']['Version'] = '2012-02-21';

SDVA($Thumbrun, array(
  'ImgRx' => "/\\.(gif|png|jpe|jpe?g|wbmp|xbm|eps|svg)$/i",
  'ThumbRx' => "/^thumb_/",
  'Px' => 128,
  'ThumbBg' => 'grey',
));

Markup('thumbrun', 'directives', 
  '/\\(:thumbrun\\s*(.*?):\\)/ei',
  "Keep('<ul class=thumbrun>'.ThumbrunList('$pagename',PSS('$1')).'</ul>')");

function ThumbrunList($pagename, $args) {
    global $UploadDir, $UploadPrefixFmt, $UploadUrlFmt, 
           $TimeFmt, $EnableDirectDownload, $Thumbrun;

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
    while (($file=readdir($dirp)) !== false) {
        if ($file{0} == '.') continue;
        if (@$filter && !preg_match(@$filter, $file)) continue;
        // match images
        if (@$Thumbrun['ImgRx'] && !preg_match(@$Thumbrun['ImgRx'], $file)) continue;
        // but skip thumbnails
        if (@$Thumbrun['ThumbRx'] && preg_match(@$Thumbrun['ThumbRx'], $file)) continue;
        $filelist[$file] = $file;
    }
    closedir($dirp);
    $out = array();
    natcasesort($filelist);
    foreach($filelist as $file=>$x) {
        $out[] = ThumbrunListFile($pagename, $file, $uploadurl, $uploaddir,
        ($opt['px'] ? $opt['px'] : $Thumbrun['Px']));
    }
    return implode("\n",$out);
} # ThumbrunList

function ThumbrunListFile($pagename, $file, $uploadurl, $uploaddir,$px) {
    global $TimeFmt, $Thumbrun;

    $link = PUE("$uploadurl$file");
    $stat = stat("$uploaddir/$file");
    $pinfo = pathinfo($file);
    $bn =  basename($file,'.'.$pinfo['extension']);
    $thumbfile = "thumb_${bn}.png";
    $thumblink = PUE("$uploadurl$thumbfile");
    ThumbrunMakeThumb($uploaddir,$file,$thumbfile,$px,$px);
    $imginfo = "<span>$file</span> "
        . "<span>" . number_format($stat['size']) . " bytes</span> "
        . "<span>" . strftime($TimeFmt, $stat['mtime']) . "</span>";
    $linkfmt = "<li><span class='item'><a href='%s'><img src='%s' alt='%s' title='%s'/></a> <span class='imginfo'>%s</span></span></li>";
    $out = sprintf($linkfmt, $link, $thumblink, $file, $file, $imginfo);

    return $out;
} # ThumbrunListFile

function ThumbrunMakeThumb($uploaddir,$file,$thumbfile,$w,$h) {
    global $Thumbrun;

    $filepath = "$uploaddir/$file";
    $thumbpath = "$uploaddir/$thumbfile";
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
