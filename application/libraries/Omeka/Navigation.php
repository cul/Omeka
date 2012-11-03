<?php
/**
 * Omeka
 * 
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

/**
 * Customized subclass of Zend Framework's Zend_Navigation class.
 * 
 * @package Omeka\Navigation
 */
class Omeka_Navigation extends Zend_Navigation
{
    const PUBLIC_NAVIGATION_MAIN_OPTION_NAME = 'public_navigation_main';
    const PUBLIC_NAVIGATION_MAIN_FILTER_NAME = 'public_navigation_main';
           
    /**
     * Creates a new navigation container
     *
     * @param array|Zend_Config $pages    [optional] pages to add
     * @throws Zend_Navigation_Exception  if $pages is invalid
     */
    public function __construct($pages = null)
    {
        parent::__construct($pages);
    }
    
    /**
     * Saves the navigation in the global options table.
     *
     * @param String $optionName    The name of the option
     */
    public function saveAsOption($optionName) 
    {
        set_option($optionName, json_encode($this->toArray()));
    }
    
    /**
     * Loads the navigation from the global options table
     *
     * @param String $optionName    The name of the option
     */
    public function loadAsOption($optionName) 
    {
        if ($navPages = json_decode(get_option($optionName), true)) {
            $this->setPages($navPages);
        }
    }
    
    /**
     * Adds a page to the container.  If a page does not have a valid id, it will give it one.
     * and is an instance of Zend_Navigation_Page_Mvc or Omeka_Navigation_Page_Uri.
     * If a direct child page already has another page with the same uid then it will not add the page.
     * However, it will add the page as a child of this navigation if one of its descendants already
     * has the page. 
     *
     * This method will inject the container as the given page's parent by
     * calling {@link Zend_Navigation_Page::setParent()}.
     *
     * @param  Zend_Navigation_Page|array|Zend_Config $page  page to add
     * @return Zend_Navigation_Container                     fluent interface,
     *                                                       returns self
     * @throws Zend_Navigation_Exception                     if page is invalid
     */
    public function addPage($page)
    {   
         // normalize the page and its subpages            
        $page = $this->_normalizePageRecursive($page);        
                
        $page->uid = $this->createPageUid($page->getHref());
        if (!($fPage = $this->getChildByUid($page->uid))) {
            return parent::addPage($page);
        }
        
        return $this;
    }
    
    /**
     * Returns an immediate child page that has a uid of $uid.  If none exists, it returns null.
     *
     * @param string $uid   The uid to search for in this navigation
     * @return Zend_Navigation_Page The page
     */
    public function getChildByUid($uid)
    {
        foreach($this->getPages() as $page) {
            if ($page->get('uid') == $uid) {
                return $page;
            }
        }
        return null;
    }
    
    /**
     * Adds a page to a container after normalizing it and its subpages 
     *
     * @param Zend_Navigation_Page $page    The page to add
     * @param Zend_Navigation_Container $container    The container to which to add the page
     * @return Zend_Navigation_Container The container with the page added
     */
    public function addPageToContainer($page, $container)
    {
        if ($container === $this) {
            return $this->addPage($page);
        }
        
        // normalize the page and its subpages            
        $page = $this->_normalizePageRecursive($page);        
                
        $page->uid = $this->createPageUid($page->getHref());
        if (!($fPage = $this->getPageByUid($page->uid, $container))) {
            return $container->addPage($page);
        }
        
        return $container;
    }
    
    /**
     * Creates an Omeka Navigation object by adding
     * pages generated by Omeka plugins and other contributors via a filter (e.x. 'public_navigation_main').
     * The filter should provide an array pages like they are added to Zend_Navigation_Container::addPages
     * However, the page types should only be one of the following types:
     * Omeka_Navigation_Page_Uri or Zend_Navigation_Page_Mvc.  
     * If the associated uri of any page is invalid, it will not add that page to the navigation. 
     * Also, it removes expired pages from formerly active plugins and other former handlers of the filter.
     * 
     * @param String $filterName    The name of the filter
     * @throws Zend_Navigation_Exception if a filter page is invalid  
     */
    public function createNavigationFromFilter($filterName='')
    {        
        if ($filterName == '') {
            $filterName = self::PUBLIC_NAVIGATION_MAIN_FILTER_NAME;
        }
        
        // create a new navigation object from the filterName
        $filterNav = new Omeka_Navigation();
                        
        // get default pages for the filter
        $pageLinks = array();
        switch($filterName) {
            case self::PUBLIC_NAVIGATION_MAIN_FILTER_NAME:
                // add the standard Browse Items and Browse Collections links to the main nav
                $pageLinks = array(  
                    new Zend_Navigation_Page_Mvc(array(
                        'label' => __('Browse Items'),
                        'controller' => 'items',
                        'action' => 'browse',
                        'visible' => true
                    )), 
                    new Zend_Navigation_Page_Mvc(array(
                        'label' => __('Browse Collections'),
                        'controller' => 'collections',
                        'action' => 'browse',
                        'visible' => true,
                    )),
                );
            break;
        }
        
        // gather other page links from filter handlers (e.g. plugins)      
        $pageLinks = apply_filters($filterName, $pageLinks);
        
        foreach($pageLinks as $pageLink) {                            
            // normalize the page and its subpages            
            $page = $this->_normalizePageRecursive($pageLink, array('can_delete' => false));
            $filterNav->baseAddPage($page);
        }
        
        return $filterNav;
    }
    
    /**
     * Add a page to the navigation using parent::addPage()
     * This needs to wrapped so that methods like createNavigationFromFilter() can add pages directly using
     * the parent class method.
     * 
     * @param  Zend_Navigation_Page|array|Zend_Config $page  page to add
     * @return Zend_Navigation_Container                     fluent interface,
     *                                                       returns self
     * @throws Zend_Navigation_Exception                     if page is invalid     
     */
    public function baseAddPage($page)
    {
        return parent::addPage($page);
    }
    
    
    /**
     * Merges a page (and its subpages) into this navigation.
     * If the page already exists in the navigation, then it attempts to add 
     * any new subpages of the page to it.  If a subpages already exists in the navigation, then it 
     * it recursively attempts to add its new subpages to it, and so on. 
     * 
     * @param  Zend_Navigation_Page $page  page to be merged
     * @return Zend_Navigation_Container  $parentContainer the suggested parentContainer for the page.
     * The parentContainer must already be in the navigation and remain so throughout the merge.                     
     * @throws Zend_Navigation_Exception    if a subpage is invalid
     * @throws RuntimeException     if the page or parentContainer is invalid  
     */
    public function mergePage(Zend_Navigation_Page $page, Zend_Navigation_Container $parentContainer=null)
    {
        if (!$page->uid) {
            // we assume that every page has already been normalized
            throw RuntimeException(__('The page must be normalized and have a valid uid.'));
        }
        
        if ($parentContainer === null) {
            $parentContainer = $this;
        }
        
        // save the child pages and remove them from the current page
        $childPages = $page->getPages();
        $page->removePages($childPages);
        
        if (!($oldPage = $this->getPageByUid($page->uid))) {    
            if ($parentContainer !== $this && !$this->hasPage($parentContainer, true)) {
                // we assume parentContainer is either the navigation object
                // or a descendant page of the navigation object 
                throw RuntimeException(__('The parent container must either be the navigation object' .
                                       ' or a descendant subpage of the navigation object.'));
            }
            
            // add the page to the end of the parent container
            $pageOrder = $this->_getLastPageOrderInContainer($parentContainer) + 1;
            $page->setOrder($pageOrder);
            $this->addPageToContainer($page, $parentContainer);
            
            // set the new parent page
            $parentPage = $page;
        } else {
            // set the new parent page
            $parentPage = $oldPage;
        }
        
        // merge the child pages
        foreach($childPages as $childPage) {
            $this->mergePage($childPage, $parentPage);
        }
    }
    
    /**
     * Returns the page order of the last child page in the container.  
     * If no page exists in the container, it returns 0.
     * 
     * @param  Zend_Navigation_Container $container  The container to search for the last page order
     * @return int    the last page order in the container
     */ 
    protected function _getLastPageOrderInContainer($container)
    {
        $lastPageOrder = 0;
        foreach($container->getPages() as $page) {
            $pageOrder = $page->getOrder();
            if ($pageOrder > $lastPageOrder) {
                $lastPageOrder = $pageOrder;
            }
        }
        return $lastPageOrder;
    }
    
    /**
     * Merges a navigation object into this navigation.
     * 
     * @param  Omeka_Navigation $nav  The navigation to merge
     */
    public function mergeNavigation(Omeka_Navigation $nav) 
    {
        // merge each page of $nav
        foreach($nav->getPages() as $page) {
            $this->mergePage($page);
        }
    }
    
    /**
     * Adds pages generated by Omeka plugins and other contributors via a filter (e.x. 'public_navigation_main').
     * The filter should provide an array pages like they are added to Zend_Navigation_Container::addPages
     * However, the page types should only be one of the following types:
     * Omeka_Navigation_Page_Uri or Zend_Navigation_Page_Mvc.  
     * If the associated uri of any page is invalid, it will not add that page to the navigation. 
     * Also, it removes expired pages from formerly active plugins and other former handlers of the filter.
     * 
     * @param String $filterName    The name of the filter
     * @throws Zend_Navigation_Exception    if a filter page is invalid  
     */
    public function addPagesFromFilter($filterName='') 
    {
        if ($filterName == '') {
            $filterName = self::PUBLIC_NAVIGATION_MAIN_FILTER_NAME;
        }

        // get filter navigation from plugins
        $filterNav = $this->createNavigationFromFilter($filterName);
        
        // prune the expired navigation pages
        $expiredPages = $this->getExpiredPagesFromNav($filterNav);
        $this->prunePages($expiredPages);
        
        // merge filter nav into navigation
        $this->mergeNavigation($filterNav);
    }
    
    /**
     * Returns an array of expired pages from this navigation, 
     * where all pages in the $excludeNav are considered non-expired.
     * 
     * @param  Omeka_Navigation $excludeNav  Pages from this navigation should not be pruned
     * @return array The array of expired pages 
     */
    public function getExpiredPagesFromNav(Omeka_Navigation $excludeNav)
    {           
        // get non-expired page uids from $excludeNav
        $nonExpiredPageUids = array();
        $iterator = new RecursiveIteratorIterator($excludeNav, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $page) {
            if (!in_array($page->uid, $nonExpiredPageUids)) {
                $nonExpiredPageUids[] = $page->uid;
            }
        }    
        
        // prune expired pages
        // only pages provided by a filter (non-deleteable) should be expired
        $otherPages = $this->getOtherPages($nonExpiredPageUids);
        $expiredPages = array();
        foreach($otherPages as $page) {
            if (!$page->can_delete) {
                $expiredPages[] = $page;
            }
        }
        
        return $expiredPages;
    }
    
    /**
     * Prunes pages from this navigation.
     * When a page is pruned its children pages are reattached to the first non-pruneable ancestor page.
     * @param Zend_Navigation_Page_Mvc|Omeka_Navigation_Page_Uri $page  The page to prune
     */ 
    public function prunePages($pages)
    {
        foreach($pages as $page) {
            $this->prunePage($page);
        }
    }
    
    /**
     * Prune page from this navigation.
     * When a page is pruned its children pages are reattached to the first non-pruneable ancestor page.
     * @param Zend_Navigation_Page_Mvc|Omeka_Navigation_Page_Uri $page  The page to prune
     */ 
    public function prunePage($page)
    {        
        $this->removePageRecursive($page, $this, true);
    }
    
    /**
     * Returns an array of all pages from navigation that
     * lack a uid in $excludePageUids
     * 
     * @param array $excludePageUids  The list uids for pages to exclude
     * @return array The array of other pages.
     */
    public function getOtherPages($excludePageUids)
    {
        // get other pages
        $otherPages = array();
        $iterator = new RecursiveIteratorIterator($this, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $page) {
            if (!in_array($page->uid, $excludePageUids)) {
                $otherPages[] = $page;
            }
        }
        return $otherPages;
    }  
        
    /**
     * Returns the navigation page associated with uid.
     * It searches all descendant pages of this navigation  
     * If not page is associated, then it returns null.
     * 
     *
     * @param String $pageUid The uid of the page
     * @param Zend_Navigation_Container $container The container within which to search for the page.
     * By default, it uses this navigation.
     * @return Omeka_Zend_Navigation_Page_Uri|Zend_Navigation_Page_Mvc|null
     */
    public function getPageByUid($pageUid, $container = null)
    {
        if ($container == null) {
            $container = $this;
        }
        
        if ($page = $container->findOneBy('uid', $pageUid)) {
            return $page;
        }
        return null;
    }
    
    /**
     * Returns the unique id for the page, which can be used to determine whether it can be added to the navigation
     *
     * @param String $href The href of the page.
     * @return String
     */
    public function createPageUid($href) 
    {
        return $href;
    }

    /**
     * Recursively removes the given page from the parent container, including all subpages
     *
     * @param Zend_Navigation_Page $page The page to remove from the parent container and all its subpages.
     * @param Zend_Navigation_Container $parentContainer The parent container (by default it is this navigation) 
     * from which to remove the page from its subpages
     * @param boolean $reattach Whether the subpages of the $page should be reattached to $parentContainer
     * @return boolean Whether the page was removed
     */
    public function removePageRecursive(Zend_Navigation_Page $page, Zend_Navigation_Container $parentContainer = null, $reattach=false)
    {
        if ($parentContainer === null) {
            $parentContainer = $this;
        }
        
        $childPages = $page->getPages();
        
        $removed = $parentContainer->removePage($page);
        if ($removed && $reattach) {
            // reattach the child pages to the container page
            foreach($childPages as $childPage) {
                $pageOrder = $this->_getLastPageOrderInContainer($parentContainer) + 1;
                $page->setOrder($pageOrder);
                $this->addPageToContainer($childPage, $parentContainer);
            }
        }
        
        foreach ($parentContainer->getPages() as $subPage) {
            $removed = $removed || $this->removePageRecursive($page, $subPage, $reattach);
        }

        return $removed;
    }
    
    /**
     * Returns the option value associated with the default navigation during installation 
     *
     * @param String $optionName The option name for a stored navigation object.
     * @return String The option value associated with the default navigation during installation.
     * If no option is found for the option name, then it returns an empty string.
     */
    public static function getNavigationOptionValueForInstall($optionName) 
    {
        $value = '';
        $nav = new Omeka_Navigation();
        switch($optionName) {
            case self::PUBLIC_NAVIGATION_MAIN_OPTION_NAME:
                $nav->addPagesFromFilter(self::PUBLIC_NAVIGATION_MAIN_FILTER_NAME);
            break;
        }
                
        if ($nav->count()) {
            $value = json_encode($nav->toArray());
        }
        return $value;
    }
    
    /**
     * Normalizes a page and its subpages so it can be added
     *
     * @param  Zend_Navigation_Page|array|Zend_Config $page  Page to normalize
     * @param  $pageOptions  The options to set during normalization for every page and subpage
     * @return Omeka_Navigation_Page_Uri|Zend_Navigation_Page_Mvc|null The normalized page
     * @throws Zend_Navigation_Exception if a page or subpage is invalid  
     */
    protected function _normalizePageRecursive($page, $pageOptions = array()) 
    {
        if ($page === $this) {
            require_once 'Zend/Navigation/Exception.php';
            throw new Zend_Navigation_Exception('A page cannot have itself as a parent');
        }
                
        // convert an array or Zend_Config to a Zend_Navigation_Page 
        if (is_array($page) || $page instanceof Zend_Config) {
            require_once 'Zend/Navigation/Page.php';
            $page = Zend_Navigation_Page::factory($page);
        }
        
        // convert a Zend_Navigation_Page_Uri page to an Omeka_Navigation_Page_Uri page
        if (get_class($page) == 'Zend_Navigation_Page_Uri') {
            $page = $this->_convertZendToOmekaNavigationPageUri($page);
        }
        
        if ($page instanceof Omeka_Navigation_Page_Uri) {
            $page->setHref($page->getHref());  // sets the href, which normalizes the uri from an href
        } elseif ($page instanceof Zend_Navigation_Page_Mvc) {
            if ($page->getRoute() === null) {
                $page->setRoute('default');
            }
        }
        
        if (!($page instanceof Zend_Navigation_Page_Mvc || $page instanceof Omeka_Navigation_Page_Uri)) {
            require_once 'Zend/Navigation/Exception.php';
            throw new Zend_Navigation_Exception(
                    'Invalid argument: $page must resolve to an instance of ' .
                    'Zend_Navigation_Page_Mvc or Omeka_Navigation_Page_Uri');
        }
        
        // set options for the page
        $page->setOptions($pageOptions);
        
        // set the uid
        $uid = $this->createPageUid($page->getHref());
        $page->set('uid', $uid);
        
        // normalize sub pages
        $subPages = array();
        foreach($page->getPages() as $subPage) {
            $subPages[] = $this->_normalizePageRecursive($subPage, $pageOptions);
        }
        $page->setPages($subPages);
            
        return $page;
    }
    
    /**
     * Converts a Zend_Navigation_Page_Uri to an Omeka_Navigation_Page_Uri
     *
     * @param Zend_Navigation_Page_Uri $page The page to convert
     * @return Omeka_Navigation_Page_Uri The converted page
     */
    protected function _convertZendToOmekaNavigationPageUri(Zend_Navigation_Page_Uri $page) 
    {   
        // change the type of page     
        $pageOptions = $this->_conditionalReplaceValueInArray($page->toArray(), 
                                                              'pages', 
                                                              'type', 
                                                              'Zend_Navigation_Page_Uri', 
                                                              'Omeka_Navigation_Page_Uri');
                
        $convertedPage = new Omeka_Navigation_Page_Uri();
        $convertedPage->setOptions($pageOptions);
        
        return $convertedPage;
    }
    
    
    /**
     * Returns an nested associative array such that all array elements have replaced an key value to 
     * a new key value only if it is equal to a specific old key value.   
     *
     * @param array $array The associative array
     * @param string $childKey The associative array
     * @param string $targetKey The target key whose value can be replaced
     * @param mixed $oldValue The old value of the element associated with the 
     * target key used to determine if the value should be changed
     * @param mixed $newValue The new value of the element associated with the target key
     * @return array The replaced associative array
     */
    protected function _conditionalReplaceValueInArray($array, $childKey, $targetKey, $oldValue, $newValue)
    {
        // change the current key value to the newValue if it is equal to the old value 
        if (isset($array[$targetKey])) {
            if ($array[$targetKey] == $oldValue) {
                $array[$targetKey] = $newValue;
            }
        }
        // change the nested subarrays located in the childKey
        if (isset($array[$childKey])) {
            $subArrays = $array[$childKey]; 
            $newSubArrays = array();
            foreach ($subArrays as $subArray) {
                $newSubArrays[] = $this->_conditionalReplaceValueInArray($subArray, 
                                                                         $childKey, 
                                                                         $targetKey, 
                                                                         $oldValue, 
                                                                         $newValue);
            }
            $array[$childKey] = $newSubArrays;
        }
        return $array;
    }
}
