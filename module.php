<?php

namespace modules\gittobook;

use diversen\cli\optValid;
use diversen\conf;
use diversen\date;
use diversen\db\q;
use diversen\db\rb;
use diversen\html;
use diversen\file;
use diversen\html\helpers;
use diversen\http;
use diversen\lang;
use diversen\log;
use diversen\moduleloader;
use diversen\pagination;
use diversen\sendfile;
use diversen\session;
use diversen\strings;
use diversen\template\assets;
use diversen\template\meta;
use diversen\uri\direct;
use diversen\user;
use diversen\valid;
use diversen\mirrorPath;
use diversen\git;
use diversen\meta as metaTags;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;
use diversen\uri;
use RedBeanPHP\R;

use modules\gittobook\share;
use modules\count\module as counter;
use modules\gittobook\cover;

class module {
    
    public function downloadAction () {
        
        $id = direct::fragment(1);
        if (!$id) {
            moduleloader::setStatus(404);
            return;
        }
        
        $repo = $this->get($id);
        if (empty($repo)) {
            moduleloader::setStatus(404);
            return;
        }
        
        $user_owns = user::ownID('gitrepo', $repo['id'], session::getUserId());
        if ($user_owns OR !$repo['private']) {
            
            $name = direct::fragment(2);
            $full = conf::pathHtdocs() . "/books/$id/$name";

            $s = new sendfile();

            $info = pathinfo($full);
            $type = $info['extension'];

            if ($type == 'mobi') {
                $s->contentType('application/mobi+zip');
            } 

            try {
                $s->send($full, false);
            } catch (Exception $e) {
                moduleloader::setStatus(404);
                return false;
            }
            
        } else {
            moduleloader::setStatus(404);
            return false;
        }
    }

    /**
     * allowed mime for html - will be checked when source is moved to
     * public
     * @var array $mime 
     */
    public $mime = ['png', 'gif', 'jpeg', 'jpg'];
    
    /**
     * some strange test action ...
     */
    public function testAction () {
        $file = '/home/dennis/www/gitbook/htdocs/books/54/06-profeternes-paradis.md';
        //$y = new Yaml();
        if (!file_exists($file)) {
            die("Test file: $file does not exists");
        }
        $yaml = new Parser();
        $values = $yaml->parse(file_get_contents($file));
        print_r($values); die;
        //echo file_get_contents($file);
    }
    
    /**
     * test action for creating a cover
     */
    public function coverAction () {
        $id = direct::fragment(2);
        $c = new cover();
        $c->create($id);
        echo html::createLink("/books/$id/cover.png", "cover");
    }
    
    /**
     * connect to database
     */
    public function __construct() {
        rb::connectExisting();
        $css = conf::getModulePath('gittobook') . "/assets.css";
        assets::setInlineCss($css);
    }

    /**
     * check if user is user
     * @param string $path
     * @param int $id
     * @return int
     */
    public function checkAccess($path) {
        if ($path == 'repos') {
            if (!session::checkAccessClean('user')) {
                moduleloader::setStatus(403);
                return 0;
            }
        }
        return 1;
    }

    /*
     * display all user repos
     * action for /gittobook/repos
     */

    public function reposAction() {
        if (!$this->checkAccess('repos')) {
            return;
        }

        echo $this->viewAddRepo();
        
        $bean = rb::getBean('gitrepo', 'user_id', session::getUserId());
        if ($bean->id) {
            $user_id = session::getUserId();
            $rows = q::select('gitrepo')->filter('user_id =', $user_id)->fetch();
            echo $this->viewRepos($rows, 
                    array(
                        'admin' => 1,
                        'options' => 1, 
                        'exports' => 1));
        }

        
    }
    
    
    /**
     * list actions public
     */
    public function indexAction() {
        
        $per_page = 10;
        $num_rows = q::numRows('gitrepo')->
                filter('published =', 1)->
                fetch();
        $pager = new pagination($num_rows, $per_page);
        
        $title = lang::translate('List of books. Page ') . $pager->getPageNum();
        meta::setMetaAll($title);
        
        $rows = q::select('gitrepo')->
                filter('published =', 1)->
                order('hits', 'DESC')->
                limit($pager->from, $per_page)->
                fetch();
        
        echo $this->viewRepos($rows);
        echo $pager->getPagerHTML();
        
    }

    /**
     * view repo rows
     * @param array $rows
     * @return string $str HTML
     */
    public function viewRepos($rows, $options = array ()) {

        $str = '';
        foreach ($rows as $row) {
            $str.= $this->viewRepo($row, $options);
        }
        return $str;
        
    }
    
    /**
     * view single repo
     * @param array $row
     * @return string $str HTML
     */
    public function viewRepo($row, $options = array ()) {

        $str = '';
        $str.=$this->viewHeaderCommon($row, $options);
        return $str;
        
    }

    /**
     * display repo options
     * @param array $row
     * @return string $str
     */
    public function optionsRepo($row) {

        $str = '';
        $str.= html::createLink("/gittobook/delete?id=$row[id]&delete=1", lang::translate('Delete'));
        $str.= MENU_SUB_SEPARATOR;
        $str.= html::createLink("/gittobook/checkout?id=$row[id]", lang::translate('Checkout'));
        return $str;
    }



    /**
     * list repos action
     */
    public function listRepos() {
        
    }

    /**
     * delete repo action
     * @return type
     */
    public function deleteAction() {

	if (!isset($_GET['id'])) {
            moduleloader::setStatus(404);
            return;
	}

        if (!user::ownID('gitrepo', $_GET['id'], session::getUserId())) {
            if (!session::isAdmin()) {
                moduleloader::setStatus(403);
                return;
            }
        }

        if (isset($_POST['delete_files'])) {
            $this->deletePublicFiles($_GET['id']);
            $this->repoDeleteFiles($_GET['id']);
            
            $db = new \modules\gittobook\db();
            $db->updateRepo($_GET['id'], array('published' => 0));

            http::locationHeader('/gittobook/repos', lang::translate('Repo files has been purged!'));
        }
        
        if (isset($_POST['delete_all'])) {
            $this->deletePublicFiles($_GET['id']);
            $this->repoDeleteFiles($_GET['id']);
            
            $db = new \modules\gittobook\db();
            $db->deleteRepo($_GET['id']);

            http::locationHeader('/gittobook/repos', lang::translate('Repo files has been purged. Database entry has been removed!'));
        }
        
        echo helpers::confirmDeleteForm(
                'delete_files', lang::translate('Remove git repo and exported files - but leave repo in database'));
        
        echo helpers::confirmDeleteForm(
                'delete_all', lang::translate('Remove everything. Be careful as any links to this repo no longer will be found!'));
    }

    /**
     * delete public files
     * @param int $id repo id
     */
    public function deletePublicFiles ($id) {
        $public_path = $this->exportsDir($id);
        file::rrmdir($public_path);
    }
    
    /**
     * delete repo files from id
     * @param int $id
     */
    public function repoDeleteFiles ($id) {
        $private_path = $this->repoPath($id);
        file::rrmdir($private_path);        
    }

    /**
     * form for adding the repo
     * @return string $str html
     */
    public function addForm() {
        $f = new html();
        $f->init(null, 'repo_add', true);
        $f->formStart();
        $f->legend(lang::translate("Add a git repo"));
        $f->label('repo', lang::translate('Enter repo URL (http|https)'));
        $f->text('repo');
        $f->submit('repo_add', lang::translate('Add'));
        $f->formEnd();
        return $f->getStr();
    }

    /**
     * var holding errors
     * @var array $errors
     */
    public $errors = array();

    /**
     * validates a repo before adding to db
     * @return type
     */
    public function validateRepo() {

        $repo = html::specialDecode($_POST['repo']);
        if (!valid::url($repo)) {
            $this->errors['url'] = lang::translate('Not a correct repo URL');
            return;
        }


        $bean = rb::getBean('gitrepo', 'repo', $repo);
        if ($bean->id) {
            $this->errors['repo'] = lang::translate('Repo already exists');
            return;
        }
        
        // without .git
        $no_dot = str_replace('.git', '', $repo);
        $bean = rb::getBean('gitrepo', 'repo', $no_dot);
        if ($bean->id) {
            $this->errors['repo'] = lang::translate('Repo already exists');
            return;
        }
        
        // with .git
        $bean = rb::getBean('gitrepo', 'repo', $no_dot . ".git");
        if ($bean->id) {
            $this->errors['repo'] = lang::translate('Repo already exists');
            return;
        }

        $command = 'git ls-remote ' . $repo;
        exec($command, $output, $return_var);
        if ($return_var) {
            $this->errors['repo'] = lang::translate('URL does not seem to be a git repo. If you know this is a git repo, please try again.');
            return;
        }
    }

    /**
     * show form, validate, add repo
     */
    public function viewAddRepo() {
        if (isset($_POST['repo_add'])) {
            $this->validateRepo();
            if (empty($this->errors)) {
                
                $db = new \modules\gittobook\db();
                $res = $db->addRepo();
                
                http::locationHeader("/gittobook/checkout?id=$res");
            } else {
                echo html::getErrors($this->errors);
            }
        }
        echo $this->addForm();
    }

    /**
     * get a preo form db
     * @param int|array $var search param id or array of search options
     * @return array $repo
     */
    public function get($var) {
        if (!is_array($var)) {
            return q::select('gitrepo')->filter('id =', $var)->fetchSingle();
        }
        return q::select('gitrepo')->filterArray($var, 'AND')->fetchSingle();
    }

    /**
     * get repo path
     * @param int $id repo id
     * @param string $type controller or file
     * @return string
     */
    public function repoPath($id) {

        $repo = $this->get($id);
        $path = git::getRepoNameFromRepoUrl($repo['repo']);
        $path = conf::pathBase() . "/private/gittobook/$id" . "/$path";
        return $path;
    }
    
    /**
     * return a books export dir
     * @param int $id
     * @return string $path
     */
    public function exportsDirWeb ($id) {
        return "/books/$id";
    }
    
    /**
     * return a books export dir with repo name
     * /books/10/a-book-name
     * @param type $row
     * @return type
     */
    public function exportsUrl($row) {
        return $this->exportsDirWeb($row['id']) . "/" . strings::utf8SlugString($row['name']);
    }

    /**
     * get array of export files with type as key and file_path as value
     * @param int $id repo id
     * @return array $ary array with type as key and file_path as value
     */
    public function exportsArray($id, $options = array()) {

        $repo = $this->get($id);
        $path = $this->exportsDirWeb($id);
        $path_full = $this->exportsDir($id);
        
        $controller_path = "/downloads/$id";
        $name = git::getRepoNameFromRepoUrl($repo['repo']);
        $exports = $this->exportFormatsIni();
        
        $ary = array ();
        foreach ($exports as $export) {
            $file = $path_full . "/$name.$export";

            if (file_exists($file)) {
                if (!isset($options['path'])) {
                    $location = $controller_path . "/$name.$export";
                    $ary[$export]= html::createLink($location, strtoupper($export));
                } else {
                    $ary[$export] = $path . "/$name.$export";//$location;
                }
            }
        }
        return $ary;
    }

    /**
     * globs a dir based on pattern
     * @param string $filepath
     * @param string $pattern
     * @return array $files dirs and files
     */
    public function globdir($filepath, $pattern = null, $flags = 0) {
        $dirs = glob($filepath . "/*", GLOB_ONLYDIR);
        $files = glob($filepath . $pattern, $flags);
        $all = array_unique(array_merge($dirs, $files));
        return $all;
    }

    /**
     * ignore files
     * @param string $file
     * @param array $options
     * @return boolean $res true or false
     */
    public function ignore($file, $options) {
        $info = pathinfo($file);
        if (!isset($options['ignore-files'])) {
            return false;
        }
        if (in_array($info['basename'], $options['ignore-files'])) {
            return true;
        }
        return false;
    }

    /**
     * get files as array
     * @param string $path
     * @param string $ext
     * @return array $ary array of files
     */
    public function getMarkdownFilesAry($id, $ext, $flags = 0) {
        $path = $this->repoPath($id); 
        $options = $this->yamlAsAry($id);
        $top = $this->globdir($path, $ext, $flags);
        $final = array();

        foreach ($top as $file) {
            if ($this->ignore($file, $options)) {
                continue;
            }

            if (is_dir($file)) {
                $files = $this->globdir($file, $ext);
                $final = array_merge($final, $files);
            } else {
                $final[] = $file;
            }
        }
        return $final;
    }

    /**
     * return name of public md file with all markdown
     * @param type $id
     * @return string
     */
    public function mdAllFile($id) {
        $row = $this->get($id);
        $md_file = $this->exportsDir($id) . "/$row[name].md";
        return $md_file;
    }
    
    /**
     * checkout or clone repo
     */
    public function checkoutAction() {
        
	if (!isset($_GET['id'])) {
            moduleloader::setStatus(404);
            return;
	}
	$id = $_GET['id'];       
        
        ?>

        <button class="uk-button checkout" type="button">Checkout and build</button>
        <div class="progress">
            <img class ="loader_gif" style="float:left;margin:3px 5px 0 0;" src="/images/load.gif" width="16" />
            <div class="loader_message"></div>
        </div>
        <div class ="result">
        </div>

        <script type="text/javascript">
   
            var $loading = $('.loader_gif').hide();
            $(document).ajaxStart(function () {
                $loading.show();
                $(".loader_message").html('<?= lang::translate("Wait while generating site. This may take a minute or two") ?>');
            }).ajaxStop(function () {
                $loading.hide();
            });
            
            $.ajaxSetup({
                beforeSend: function(xhr) {
                    $(window).bind('beforeunload', function() {
                        xhr.abort();
                    });
                }
            });
            
            $(document).ready(function(){
                $( ".checkout" ).click(function( event ) {
                    event.preventDefault();
                    $.post("/gittobook/ajax?id=<?= $id ?>", function (data) {
                        $('.loader_message').append(data);
                    });
                });
            });

            
        </script>
        <?php
    }
    
    /**
     * Get various info from repo url
     * @staticvar array $meta
     * @param int $id
     * @return array $meta
     */
    public function metaFromRepo ($id) {
        
        static $meta;
        if ($meta) {
            return $meta;
        }
        
        $meta = [];
        
        $row = $this->get($id);
        $parts = parse_url($row['repo']);
        $path_parts = explode( '/', $parts['path']);
        
        // Author from repo name
        $meta['author'][] = $path_parts[1];
        
        // Title
        $title = $path_parts[2];
        $title = ucfirst(str_replace('-', ' ', $title));
        $meta['title'] = $title;
        
        // Subtitle from meta-tags
        $m = new metaTags(); 
        $meta_tags = $m->getMeta($row['repo']);
        
        $sub = '';
        if (isset($meta_tags['title'])) {
            $sub = $meta_tags['title'];
        }

        $meta['Subtitle'] = $sub;
        return $meta;
    }

    /**
     * Perform ajax call
     */
    public function ajaxAction() {
       
	if (!isset($_GET['id'])) {
            moduleloader::setStatus(404);
            return;
	}
 
        $sleep = 0;
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        // $format = filter_var($_GET['format']);
        
        $is_user = user::ownID('gitrepo', $id, session::getUserId());
        if (!$is_user AND !session::isAdmin()) {
            echo lang::translate("You can not perform any action on this page.");
            die();
        }
        
        session_write_close();
        
        ignore_user_abort(true);
        
        sleep($sleep);

        $this->ajaxGenerateFiles($id);

        $options = $this->yamlAsAry($id);
        $db_values = ['private' => $options['private']];
        if (isset($this->errors['yaml'])) {
            $lang = lang::translate('yaml error. Yaml file is not used. ');
            echo html::getErrors($lang);
            echo html::getErrors($this->errors['yaml']);
        }
        
        $formats = $this->exportFormatsReal($options['format-arguments']);
        
        // html
        if (in_array('html', $formats) ) {
            
            // run pandoc
            $ret = $this->pandocCommand($id, 'html', $options);
            if ($ret) {
                $db_values['published'] = 0;
                
                $db = new \modules\gittobook\db();
                $db->updateRepo($id, $db_values);

                //die();
                $this->errors[] = lang::translate('Could not publish HTML');
            } 
            
            // html not self-contained
            $res = $this->moveAssets($id, 'html', $options);
            if (!$res) {
                $this->errors[] = lang::translate('Could not move all HTML assets');
            } else {
                $db_values['published'] = 1;
                
                $db = new \modules\gittobook\db();
                $db->updateRepo($id, $db_values);
            }
        }

        // html-chunked
        if (in_array('html-chunked', $formats) ) {

            // $files = glob("*.{jpg,png,gif}", GLOB_BRACE);
            $files_md = $this->getMarkdownFilesAry($id, '/*.md');
            $files_markdown = $this->getMarkdownFilesAry($id, '/*.markdown');
            
            $files = array_merge($files_md, $files_markdown); 
            
            
            $repo_path = $this->repoPath($id);
            
            $ret = 0;
            foreach($files as $file) {
            
                // get export file name and create dirs
                $info = pathinfo($file);
                //$file = $info['']
                // mv md file
                copy($file, $this->exportsDir($id). "/" . $info['basename']);
                
                // generate html
                $export_file = $this->exportsDir($id) . "/" . $info['filename'] . ".html";
                
                $command = "cd $repo_path && ";

                // add base flags
                
                $base_flags = $this->pandocArgs($id, 'html-chunked', $options);
                $command.= "pandoc " . escapeshellcmd($base_flags) . " ";
                $command.= "-o '" . $export_file . "' ";
                $command.= "'$file'" . " 2>&1";
                $output = array();
                exec($command, $output, $ret);
                if ($ret) {
                    echo lang::translate("Failed to create export of type: ") . "html-chunked" . "<br />";
                    echo html::getErrors($output);
                    echo $command;
                    log::error($command);
                    log::error($output);
                    break;
                } 
            }
            
            if (!$ret) {
                $db_values['published'] = 1;
                
                $db = new \modules\gittobook\db();
                $db->updateRepo($id, $db_values);
            }
            
            $menu = $this->generateMenu($id, $files);
            $save_menu = $this->exportsDir($id) . "/menu.html";
            file_put_contents($save_menu, $menu);

            // html not self-contained
            $res = $this->moveAssets($id, 'html', $options);
            if (!$res) {
                $this->errors[] = lang::translate('Could not move all HTML assets');
            } else {
                $db = new \modules\gittobook\db();
                $db->updateRepo($id, $db_values);
            }
            
            
            if (!$ret) {
                echo lang::translate("Done ") . "html (chunked)" . "<br/>";
            }
        }
        
        // epub
        if (in_array('epub', $formats) ) {
            $this->pandocCommand($id, 'epub', $options);
        }
        
        // mobi
        if (in_array('mobi', $formats) ) {    
            $this->kindlegenCommand($id, 'mobi', $options);
        }
        
        // pdf
        if (in_array('pdf', $formats)) {
            $this->pandocCommand($id, 'pdf', $options); 
        }
        
        if (in_array('docx', $formats)) {
            $this->pandocCommand($id, 'docx', $options);
            
        }
        
        // docbook
        if (in_array('docbook', $formats)) {
            $this->pandocCommand($id, 'docbook', $options);
        }
        
        // texi
        if (in_array('texi', $formats)) {
            $this->pandocCommand($id, 'texi', $options);
        }
        die();
    }
    

    
    /**
     * generates a html menu from md files
     * @param type $files
     */
    public function generateMenu ($id, $files) {

        $str = '<div id="TOC"><ul>';        
        foreach ($files as $file) {

            $f = fopen($file, 'r');
            $line = fgets($f);
            $line = trim($line);
            fclose($f);
            
            $str.='<li>';
            $title = str_replace('#', '', $line);
            $str.= $this->generateMenuLink($id, $file, $title);

            $str.='</li>';
        }
        $str.= '</ul></div>';
        return $str;
    }
    
    public function generateMenuLink ($id, $file, $title) {
        
        $info = pathinfo($file);
        if (empty($title)) {
            $title = $info['filename'];
        }
        $url = "/books/$id/" . rawurlencode($info['filename']);
        return html::createLink($url, $title);
    }
    
    /**
     * first part of ajax call
     * checkout or clone repo. 
     * save meta, generate images, save repo to db
     * @param int $id repo $id
     */
    public function ajaxGenerateFiles ($id) {
        
        echo "<br />";
        $res = $this->execCheckout($id);
        if ($res) {
            $error = lang::translate('Could not checkout repo - You need to remove the repo and try again.') . "<br />";
            echo html::getError($error);
            die();
        } else {
            echo lang::translate('Updated repo ') . "<br />";
        }
        
        // remove old builds
        $public_path = $this->exportsDir($id);
        file::rrmdir($public_path);
        
        // mkdir public
        $fs = new Filesystem();
        $fs->mkdir($public_path, 0777);
        
        // generate cover
        $yaml = $this->yamlAsAry($id, true);

        
        $c = new cover();
        
        // Default is not set
        if ($yaml['cover-image'] == 'Not set') {
            $c->create($id, $yaml);
            $cover_image = conf::pathHtdocs() . "/books/$id/cover.png"; 
        } else {
            $cover_image = $this->repoPath($id) . "/" . $yaml['cover-image'];
        }
        
        if (!file_exists($cover_image)) {
            //echo "does not exists";
            $error = lang::translate('Cover file does not exists in repo: ') . $yaml['cover-image'] . ". "; 
            $error.= lang::translate('Correct path and re-build if you want your own cover. We use a auto generated cover');
            echo html::getError($error);
            $c->create($id, $yaml);
            $cover_image = conf::pathHtdocs() . "/books/$id/cover.png"; 
        }
        
        $mime = file::getSecMime($cover_image);
        if (!in_array($mime, $this->mime)) {
            $error = lang::translate('Your cover image does not have the correct type. Allowed types are gif, jpg, jpeg, png') . ". ";
            $error.= lang::translate('Correct image and re-build. We use a default cover');
            $c->create($id, $yaml);
            $cover_image = conf::pathHtdocs() . "/books/$id/cover.png"; 
            echo html::getError($error);
        }
        
        $image_path = $c->scale($id, $cover_image);
        $yaml['cover-image'] = conf::pathHtdocs()  . "$image_path";
        
        // generate yaml meta in exports
        $yaml_res = $this->yamlExportYaml($id, $yaml);
        if (!$yaml_res) {
            echo html::getError('Could not write to filesystem. If you are admin you should fix this.');
        }
        
        // create a single file with yaml and markdown
        $md_file = $this->mdAllFile($id);
        $str = $this->filesAsStr($id);
        
        $write_res = file_put_contents($md_file, $str);
        if (!$write_res) {
            echo lang::translate('Could not write to file system. Be sure to have some .md or .markdown files:')  . $md_file . "<br />";
            die();
        }
        
        // print_r($yaml); die;
        $bean = rb::getBean('gitrepo', 'id', $id);
        $bean->subtitle = $yaml['Subtitle'];
        $bean->title = $yaml['title'];
        $bean->image = $image_path; 
        $bean->author = $yaml['author'][0];
        return R::store($bean);
    }
    
    


    /**
     * return gittobook.ini gittobook_exports as array
     * @return array $ary exports from ini
     */
    public function exportFormatsIni() {
        $exports = conf::getModuleIni('gittobook_exports');
        return explode(",", $exports);
    }
    
    /**
     * get export formats real. Formats which is both in ini settings and 
     * format-arguments
     * @param array $options format-arguments
     * @return array $ary formats
     */
    public function exportFormatsReal ($options) {
        if (empty($options)) {
            $options = array ();
        }
        
        $ini = $this->exportFormatsIni();
        $ary = array ();
        foreach($options as $key => $val) {
            if (in_array($key, $ini)) {
                $ary[] = $key;
            }
        }
        return $ary;
    }

    /**
     * moves assets for html
     * @param int $id
     * @param string $type
     * @param array $options
     * @return boolean
     */
    public function moveAssets($id, $type, $options) {

        // move to dir
        $repo_path = $this->repoPath($id);
        $export_path = $this->exportsDir($id);

        $m = new mirrorPath();
        $m->deleteBefore = false; // Delete before mirroring. Default setting
        $m->allowTypes = $this->mime;

        $m->mirror($repo_path, $export_path); // mirror
        return true;
    }

    /**
     * return boolean based on $files given. If one does not match return false
     * @param array $files
     * @param string $dir
     * @return boolean $res 
     */
    public function checkLegalAssets($files, $dir) {

        foreach ($files as $file) {
            $file_base = basename($file);
            $mime = file::getMime($file);
            
            if (!in_array($mime, $this->mime)) {
                $illegal = "$dir/$file_base";
                $this->errors[] = lang::translate('You have a file in your css path with wrong mime-type. Found file: ') . $illegal;
                $this->errors[] = lang::translate("Remove it from your repo with: git rm -f ") . $illegal;
                return false;
            }
        }
        return true;
    }

    /**
     * get yaml default string
     * @return string $str
     */
    public function yamlDefaultStr () {
        $date = date::getDateNow();
        $cover = 'Not set'; /* config::getModulePath('gittobook') . "/images/cover.jpg"; */
        $template = conf::getModulePath('gittobook') . "/templates/template.html";
        $chunked = conf::getModulePath('gittobook') . "/templates/chunked.html";
        $str = <<<EOF
---
title: Untitled
Subtitle: Author has not added a subtitle yet
subject: Not known
author:
- John Doe
- And others
keywords: ebooks, pandoc, pdf, html, epub, mobi
rights: Creative Commons Non-Commercial Share Alike 3.0
language: en-US
cover-image: {$cover}
date: '{$date}'
private: 0
# default formats
format-arguments:
    pdf: -s -S --toc
    docx: -s -S --toc
    html: -s -S --template={$template} --chapters --number-sections --toc
    #html-chunked: -s -S --template={$chunked} --chapters --number-sections --toc
    epub: -s -S  --epub-chapter-level=3 --number-sections --toc
    mobi: ok
ignore-files:

...
EOF;
        return $str;
    }
                
    /**
     * some default options when meta.yaml is not supplied.
     * @return string $str defauly yaml options
     */
    public function yamlDefaultAry($id = null) {
        
        $row = $this->get($id);

        
        
        $str = $this->yamlDefaultStr();
        $yaml = new Parser();
        $parsed = $yaml->parse($str);

        
        
        return $parsed;
    }
    
    public function repoAuthor ($repo_url) {
        $name = git::getRepoNameFromRepoUrl($repo_url);
        return $name;
    }

    /**
     * Returns parsed yaml
     * @param int $id repo id
     * @return array $values yaml as array
     */
    public function yamlAsAry($id, $fetch_meta = false) {
        
        $yaml = new Parser();
        $file = $this->repoPath($id) . "/meta.yaml";
        
        $values = [];
        if (file_exists($file)) {  
            try {
                $values = $yaml->parse(file_get_contents($file));
            } catch (Exception $e) {
                $this->errors['yaml'] = $e->getMessage();
                $values = $this->yamlDefaultAry($id);
            }
            $values = $this->yamlFix($values);
            
        } else {
            $values = $this->yamlDefaultAry($id);
            if ($fetch_meta) {
                $meta = $this->metaFromRepo($id);
                $values = array_merge($values, $meta );
            }            
        }
        
        return $values;
    }
    
    /**
     * if any of the values is missing in meta.yaml
     * we insert default values
     * @param array $values
     * @return array $values
     */
    public function yamlFix ($values) {
        $default = $this->yamlDefaultAry();
        return array_merge($default, $values);
    }

    /**
     * returns dir where exports will be put
     * @param type $id
     * @return type
     */
    public function exportsDir($id) {
        if (!$id) {
            die('exportsDir() function should always get and ID ');
        }
        $exports_dir = conf::pathHtdocs() . "/books/$id";
        file::mkdirDirect($exports_dir);
        return $exports_dir;
    }

    /**
     * gets all md files as a single string
     * @param type $id
     * @return string|boolean
     */
    public function filesAsStr($id) {
       
        // $files = $this->getMarkdownFilesAry($id, '/*.{md,markdown}', GLOB_BRACE);

        $md_files = $this->getMarkdownFilesAry($id,  "/*.md");
        $markdown_files = $this->getMarkdownFilesAry($id,  "/*.markdown");
        
        $files = array_merge ($md_files, $markdown_files);
        if (empty($files)) {
            return false;
        }

        $files_str = '';
        $yaml_file = $this->exportsDir($id) . "/meta.yaml";
        if (file_exists($yaml_file)) {
            $files_str.= file_get_contents($yaml_file) . "\n\n";
        }
        
        foreach ($files as $file) {
            $files_str.= file_get_contents($file) . "\n";
        }
        return $files_str;
    }
    
    /**
     * generates a yaml file and place it in export dir
     * @param int $id repo id
     * @return boolean $res 
     */
    public function yamlExportYaml ($id, $yaml) {
        
        if ($yaml['title'] == 'Untitled') {
            $repo = $this->repoPath($id);
        }
        
        $dumper = new Dumper();
        $str = $dumper->dump($yaml, 2);        
        $str = "---\n" . $str . "...\n\n\n";
        $yaml_file = $this->exportsDir($id) . "/meta.yaml";
        return file_put_contents($yaml_file, $str);
    }

    /**
     * get a parse option. From meta.yaml format-arguments or we use base options
     * @param array $options
     * @return string
     */
    public function pandocArgs($id, $type, $options) {
        $key = 'format-arguments';
        if (isset($options[$key])) {
            $o = $options[$key];
            if (isset($o[$type])) {
                
                $ok = $this->pandocValidate($o[$type], $type);
                if (!$ok) {
                    return false;
                }
                $o[$type].= $this->pandocAddArgs($id, $type);
                return $o[$type];//$o[$type];
            }
        }
        return "-s -S --chapters --self-contained --number-sections --toc ";
    }
    
    /**
     * adds some default arguments to pandoc build command
     * @param string $id repo id
     * @param string $type e.g. html or pdf
     * @return string $str modified string
     */
    public function pandocAddArgs ($id, $type) {
        $str ='';
        if ($type == 'html') {
            $template = conf::getModulePath('gittobook') . "/templates/template.html";
            $str.= " --template=$template -t html5 ";
        }
        
        if ($type == 'html-chunked') {
            $template = conf::getModulePath('gittobook') . "/templates/chunked.html";
            $str.= " --template=$template -t html5 ";
        }
        
        if ($type == 'docbook') {
            $str.= " -t docbook ";
        }
        
        if ($type == 'pdf') {
            $str.= " --latex-engine=xelatex ";
        }
        
        if ($type == 'docx') {
            $str.= "  ";
        }
        
        // +line_blocks
        // markdown_github
        // +yaml_metadata_block
        // $str.= " --from=markdown-raw_html+line_blocks";
        $str.= " --from=markdown_github+yaml_metadata_block-raw_html+line_blocks";
        // $str.= " --from=markdown_github+yaml_metadata_block";
        return $str;
    }
    
    /**
     * validate option string
     * @param string $str
     */
    public function pandocValidate($str, $type) {

        // parse commandline options with php 
        // command line options usaually start with - and --
        //$str = "-s -S --cchapters=7 -V geometry:margin=1in -V documentclass=memoir -V lang=danish";

        $allow = array(
            // Produce typographically correct output, converting straight quotes to curly quotes 

            'S' => null,
            'smart' => null,
            // Specify the base level for headers (defaults to 1).
            'base-header-level' => null,
            // Produce output with an appropriate header and footer 
            's' => null,
            'standalone' => null,
            // Include an automatically generated table of contents
            'toc' => null,
            // Specify the number of section levels to include in the table of contents. The default is 3
            'toc-depth' => null,
            
            // no highlight of language
            'no-highlight' => null,
            // Options are pygments (the default), kate, monochrome, espresso, zenburn, haddock, and tango.
            
            'highlight-style' => null,
            // Produce HTML5 instead of HTML4. 
            'html5' => null,
            // Treat top-level headers as chapters in LaTeX, ConTeXt, and DocBook output.
            'chapters' => null,
            // Number section headings in LaTeX, ConTeXt, HTML, or EPUB output.
            'N' => null,
            'number-sections' => null,
            // Link to a CSS style sheet (for HTML - not allowed). 
            //'c' => null,
            //'css' => null,
            // user template
            
            'template' => null,
            // Use the specified CSS file to style the EPUB
            'epub-stylesheet' => null,
            'epub-chapter-level' => '1-6',
            // epub-embed-font
            'epub-embed-font' => null,
            // Specify output format.

            'V' => array(
                'geometry:margin',
                'documentclass', 
                'lang',
                'fontsize',
                'mainfont',
                'sansfont',
                'monofont',
                'boldfont',
                'version',
                'toc-depth'),
        );

        $o = new optValid();
        $ary = $o->split($str);
        $ary = $o->getAry($ary);
        $ary = $o->setSubVal($ary);
        $ok = $o->isValid($ary, $allow);
        
        if (!$ok) {
            $this->pandocArgErrors($o->errors, $type);
            return false;
        } else {
            return true;
        }
        
    }
    
    /**
     * set pandoc args errors
     * @param array $errors
     * @param string $type
     */
    public function pandocArgErrors ($errors, $type) {
        
        foreach($errors as $error) {
            $str = lang::translate('Found illigal options in <span class="notranslate"><b>format-arguments</b></span>: ');
            $str.= "'$error' ";
            $str.= lang::translate("in type ") . "'$type'. ";
            $str.= lang::translate('Remove it from <b>meta.yaml</b>');
            $this->errors[] = $str;
            
        }
    }
    
    /**
     * runs kindlegen on a epub file
     * @param int $id
     * @param string $type
     * @param array $options
     * @return int $res
     */
    public function kindlegenCommand($id, $type, $options) {
        
        exec("kindlegen", $output, $ret);
        if ($ret) {
            log::error('Kindlegen was not found on system');
            return $ret;    
        }

        $repo = $this->get($id);
        $export_dir = $this->exportsDir($id);

        // command
        $command = "cd $export_dir && kindlegen " .
                "'$repo[name].epub'" .
                " -o " .
                "'$repo[name].mobi'";

        exec($command, $output, $ret);
        if ($ret) {
            $errors = array ();
            $errors[] = lang::translate('You will need to have a title and a cover image when creating MOBI files from Epub files');
            $errors[] = lang::translate('All image paths needs to be correct when creating the MOBI file.');
            echo html::getErrors($errors);
            log::error($command);
            return $ret;
        }
        echo lang::translate('Done ') . "Mobi<br/>";
        return $ret;
    }

    /**
     * runs a pandoc command based on repo id 'type' ,e.g. epub, and options
     * @param int $id 
     * @param string $type pdf, mobi, epub, etc. 
     * @param array $options
     */
    public function pandocCommand($id, $type, $options = array()) {

        // repo name
        $repo = $this->get($id);
        $repo_name = git::getRepoNameFromRepoUrl($repo['repo']);

        // get export file name and create dirs
        $export_file = $this->exportsDir($id) . "/$repo_name.$type";

        // get repo path
        $repo_path = $this->repoPath($id);

        // begin command
        $command = "cd $repo_path && ";

        // add base flags
        $base_flags = $this->pandocArgs($id, $type, $options);
        
        if (!$base_flags) {
            echo html::getErrors($this->errors);
            return 128;
        }
        
        $base_flags = escapeshellcmd($base_flags);

        $command.= "pandoc $base_flags ";
        $command.= "-o '$export_file' ";
        
        $files_str = $this->mdAllFile($id);
        if ($files_str === false) {
            echo lang::translate("Error. You will need to have .md files written in markdown in your repo. No such files found!");
            die();
        }

        $command.= escapeshellcmd($files_str) . " 2>&1";
        $output = array();
        exec($command, $output, $ret);
        if ($ret) {
            echo lang::translate("Failed to create export of type: ") . "$type" . "<br />";
            echo html::getErrors($output);
            log::error($command);
            log::error($output);
        } else {
            echo lang::translate("Done ") . $type . "<br/>";
        }
        return $ret;
    }

    /**
     * simple check to see if a path is a git repo
     * @param array $repo
     * @return boolean
     */
    public function isRepo($row) {
        $repo_path = $this->repoCheckoutPath($row);
        $repo = $repo_path . "/$row[name]/.git";
        if (file_exists($repo)) {
            return true;
        }
        return false;
    }

    /**
     * checkout repo - clone if it does not exists
     * @param int $id repo id
     * @return int $res return value from shell command
     */
    public function execCheckout($id) {

        $row = q::select('gitrepo')->filter('id =', $id)->fetchSingle();
        /*
        if (!$this->isRepo($row)) {
            $res = $this->execClone($row);
        } else {
            $res = $this->checkout($row);
        }*/
        $res = $this->execClone($row);
        return $res;
    }

    /**
     * clone a repo to file system
     * @param array $row
     * @return int $res
     */
    public function execClone($row) {
        $clone_path = $this->repoCheckoutPath($row);
        
        $command = "cd $clone_path  && git clone  $row[repo] --depth 1";
        exec($command, $output, $res);
        if ($res) {
            log::error($output);
        }
        return $res;
    }

    /**
     * checkout a repo to master 
     * @param array $row db row
     * @return int $res result of exec
     */
    /*
    public function checkout($row) {
        $checkout_path = $this->repoCheckoutPath($row);
        $checkout_path.= "/$row[name]";
        
        $command = "cd $checkout_path && git pull ";
        exec($command, $output, $res);
        if ($res) {
            log::error($output);
        }
        return $res;
    }*/

    /**
     * get place where we checkout repo
     * @param string $repo
     * @return string $path
     */
    public function repoCheckoutPath($repo) {

        $path = conf::pathBase() . "/private/gittobook/" . $repo['id'];
        
        $f = new Filesystem();
        $f->remove($path);
        
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
        return $path;
    }  
    
    /**
     * books action
     * @return boolean
     */
    public function booksAction () {
        
        // get repo id
        $id = direct::fragment(1);
        
        // check if repo is published
        $row = $this->get(array('id =' => $id));
        if (empty($row)) {
            moduleloader::setStatus(404);
            return false;
        }
        
        $user_owns = user::ownID('gitrepo', $id, session::getUserId());
        if ($row['published'] == 0 && !$user_owns) {
            moduleloader::setStatus(404);
            return false;
        }

        
        // increment
        $c = new counter();
        $c->increment('gitrepo', 'hits', $id);
        
        // set meta info
        $yaml = $this->yamlAsAry($id);
        
        if (isset($yaml['language'])) {
            conf::setMainIni('lang', $yaml['language']);
        }
        
        $str = '';
        $options = array ('share' => 1, 'exports' => 1 );
        $str.= $this->viewHeaderCommon($row, $options);
            
        // chunked precede single html document
        if (isset($yaml['format-arguments']['html-chunked'])) {
            $file = $this->mdFilePathFull($id);           
            $title = $this->htmlTitle($file);
            if (!empty($title)) {
                $yaml['title'].= MENU_SUB_SEPARATOR . $title;
                $yaml['Subtitle'] = $yaml['title'] . ". " . $yaml['Subtitle'];
            }
            $chunked = $this->htmlChunked($id);
            if ($chunked === false) {
                moduleloader::setStatus(404);
                return;
            }
            $str.=$chunked;
        } else {
            $str.= $this->htmlSingle ($id);
        }
        
        // set meta
        //$author = $this->author($yaml['author']);
        $sub_title = $row['subtitle'] . ' ' . lang::translate('(Read online - or Download as Epub or Mobi file)');
        meta::setMetaAll(
                $row['title'], $sub_title, $yaml['keywords'], $row['image'], 'book', $row['author']);
        
        echo $str;
    }
    
    public function author ($author) {
        if (is_array($author)) {
            return implode(', ', $author);
        }
        return $author;
    }
    
    /**
     * get a better title from html file
     * reading first line header
     * @param string $file
     * @return string $str
     */
    public function htmlTitle ($file) {        
        $line = @fgets(fopen($file, 'r'));
        return trim(str_replace(['#','-'], [''], $line));
    }
    
    /**
     * get full file path of a html file based on id
     * and uri part (2)
     * @param int $id
     * @return string $path
     */
    public function htmlFilePathFull ($id) {
        // get file name
        $file = rawurldecode(direct::fragment(2));
        return conf::pathHtdocs() . "/books/$id/$file.html";
    }
    
    /**
     * get full file path of a html file based on id
     * and uri part (2)
     * @param int $id
     * @return string $path
     */
    public function mdFilePathFull ($id) {
        // get file name
        $file = rawurldecode(direct::fragment(2));
        return conf::pathHtdocs() . "/books/$id/$file.md";
    }
    
    

    /**
     * get html - not chunked
     * @param int $id
     * @param string $html_file
     * @return string $str
     */
    public function htmlSingle ($id) {

        $html_file = $this->htmlFilePathFull($id);
        $main_url = $this->repoMainUrl($id);
        
        http::permMovedHeader($main_url);
        meta::setCanonical($main_url);
        
        $str = '';
        if (file_exists($html_file)) {
            if (file_exists($html_file)) {
                $str.= file_get_contents($html_file);
            } 
        }
        return $str;
    }

    /**
     * get chunked html
     * @param int $id
     * @return string $str
     */
    public function htmlChunked($id) {
        
        $repo = $this->get($id);
        $main_url = $this->exportsUrl($repo);

        // get file name
        $file = rawurldecode(direct::fragment(2));
        $html_file = $this->htmlFilePathFull($id);
        
        //$main_url = $this->repoMainUrl($id);
        $menu_html = conf::pathHtdocs() . "/books/$id/menu.html";
        
        $str = '';
        if (file_exists($menu_html)) {
            $str.= file_get_contents($menu_html);
        }

        // if current file is not equal repo name 
        if ($repo['name'] != $file) {
            if (file_exists($html_file)) {
                $str.= file_get_contents($html_file);
            } else {
                return false;
            }
        }
        return $str;
    }
    
    /**
     * get repo main url /books/id/some-book
     * @param int $id repo id
     * @return string $url repo main url
     */   
    public function repoMainUrl ($id) {
        $repo = $this->get($id);
        return $this->exportsUrl($repo);
    }

    /**
     * get repo headers common
     * @param array $repo
     * @param array $options
     * @return string $html
     */
    public function viewHeaderCommon ($repo, $options = array ()) {
        
        // encode
        $repo = html::specialEncode($repo);
        
        // exports url
        $url = $this->exportsUrl($repo);
        
        $desc_td_class = '<td class="uk-width-3-10">';
        
        // string
        $str = '';
        
        if (empty($repo['title'])) {
            $repo['title'] = lang::translate('Untitled');
        }
        
        $str.= html::getHeadline(html::createLink($url, $repo['title']), 'h3'); //, html::getHeadline($repo['title']));
        if (isset($options['admin'])) {
            return $str;
        }
        
        $str.= html::getHeadline($repo['subtitle'], 'h5');
        $str.= html::tableBegin('gb_table uk-table');
        $str.= '<tr>';
        $str.= $desc_td_class;
        $str.= lang::translate('Repo URL: ');
        
        $str.='</td>';
        $str.= "<td>";
        $str.= html::createLink($repo['repo'], $repo['repo']) . "<br />";
        $str.='</td>';
        $str.='</tr>';
        
        $str.='<tr>';
        $str.= $desc_td_class;

        $str.= lang::translate('Edited by: ');
        $str.='</td>';
        $str.= "<td>";
        $str.= user::getProfileLink($repo['user_id']); 
        $str.='</td>';
        $str.='</tr>';
        
        $str.='<tr>';
        $str.= $desc_td_class;

        $str.= lang::translate('Cover image: ');
        $str.='</td>';
        $str.='<td>';
        $str.= html::createLink( $repo['image'], 'Cover image');
        $str.='</td>';
        $str.='</tr>';
        
        if (user::ownID('gitrepo', $repo['id'], session::getUserId())) {
            $options['options'] = 1;
        }
        
        if (session::isAdmin()) {
            $options['options'] = 1;
        }
        
        if (isset($options['options'])) {
            $str.='<tr>';
            $str.= $desc_td_class;
            $str.= lang::translate('Options');
            $str.='</td>';
            $str.='<td>';
            $str.= $this->optionsRepo($repo);
            $str.='</td>';
            $str.='</tr>';
        }
        

        $s = new share();
        $info = uri::getInfo();
        
        if ($info['controller'] != 'index' AND $info['controller'] != '') {
            $str.= '<tr>';
            $str.= $desc_td_class;
            $str.= lang::translate('Share this using: ');
            $str.= '</td>';
            $str.= '<td>';
            $str.= $s->getShareString($repo['title'], $repo['subtitle']);
            $str.= '</td>';
            $str.= '</tr>';
        }



        $user_owns = user::ownID('gitrepo', $repo['id'], session::getUserId());
        if ($user_owns OR !$repo['private']) {
            $ary = $this->exportsArray($repo['id']);
            unset($ary['html']);
            $str.='<tr>';
            $str.='<td>';
            $str.= lang::translate('Exports: ');
            $str.='</td>';
            $str.='<td>';
            $str.= implode(MENU_SUB_SEPARATOR, $ary);
            $str.='</td>';
            $str.='</tr>';
        } else {
            $str.='<tr>';
            $str.='<td>';
            $str.= lang::translate('Exports: ');
            $str.='</td>';
            $str.='<td>';
            $str.= lang::translate('Private');
            $str.='</td>';
            $str.='</tr>';
        }
        $str.='</table>';
        return $str;
    }

}
