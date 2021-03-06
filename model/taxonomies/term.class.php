<?php
/**
 * A "Term" is a descendent of a taxonomy (child, grandchild, etc).  Whether or not the
 * the Taxonomy is hierarchical or not depends entirely on the user: they are free to
 * create a hierarchical structure.
 *
 * The properties attribute (stored as JSON) helps track the hierarchy and moves the
 * expensive database queries that traverse the tree to the "on-save" instead of forcing
 * the user to wait.
 *
 * WARNING: this gets nuts because when you save a child, it updates
 * the parent behind your back!
 *
 * Here is the structure of the properties array that exists in *every* Term:
 *
 * Array(
 * 'fingerprint' => used to determine if a term was updated
 * 'prev_parent' => used to determine if a term was moved.
 * 'children_ids' => Array(
 * 123 => true,
 * 456 => true,
 * ... etc...
 * ),
 * 'children' => Array(
 * $page_id => Array(
 * 'alias' => $alias
 * 'pagetitle' => $pagetitle
 * 'published' => $published
 * 'menuindex' => $menuindex
 * 'children' => Array(**RECURSION of the $page_id array**)
 * ),
 * )
 * )
 *
 * Explanation of the nodes:
 * fingerprint - an md5 signature of the term, used to calc. whether a term changed
 * prev_parent - page id used to determine if a term was moved up/down in the hierarchy
 * children_ids - hash. Page ids are stored as keys so they can located and set/unset easily.
 * This list lets us quickly query for hierarchical data. E.g. searching for "Dogs"
 * should return products tagged as "mammals"
 * children - nested data structure defining all we need to know to generate a quickie list
 * of sub-terms below the current term.
 *
 * This structure appears in the Taxonomy pages properties (along with some other stuff).

 */
require_once MODX_CORE_PATH . 'model/modx/modprocessor.class.php';
require_once MODX_CORE_PATH . 'model/modx/processors/resource/create.class.php';
require_once MODX_CORE_PATH . 'model/modx/processors/resource/update.class.php';

class Term extends modResource
{
    public $showInContextMenu = true;

    function __construct(xPDO & $xpdo)
    {
        parent:: __construct($xpdo);
        $this->set('class_key', 'Term');
        $this->set('hide_children_in_tree', false);
    }

    /**
     * Calculates a signature fingerprint for the Term in its current state.
     * Used to determine if the term has changed.  The calculation must include
     * parent (most importantly) so we can ripple changes up through the parents
     * in the hierarchy if this term (a child) is moved.  The fingerprint calculation
     * should also include *all* data points stored in the "children" array hierarchy.
     *
     * @return string
     */
    public function calcFingerprint()
    {
        $properties = $this->get('properties');
        $children = $this->xpdo->getOption('children', $properties, array());
        return md5($this->get('parent') . $this->get('alias')
            . $this->get('pagetitle') . $this->get('menuindex') . $this->get('published').json_encode($children));
    }

    /**
     * Output array is key/value such that key = term_id, value = boolean true
     * @param $obj
     * @param $term_ids array of pre-existing children_ids in key-array so  that key = term_id, value = true
     * @return array
     */
    public function flattenChildrenObjectsToIds($obj, $term_ids = array())
    {
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Flattening Children Object: ' . print_r($obj,true).' existing children_ids: '.print_r($term_ids,true), '', __CLASS__, basename(__FILE__), __LINE__);
        foreach ($obj as $term_id => $stuff) {
            $term_ids[$term_id] = true;
            if (isset($stuff['children']) && is_array($stuff['children'])) {
                $term_ids = $this->flattenChildrenObjectsToIds($stuff['children'], $term_ids);
            }
        }
        return $term_ids;
    }

    /**
     * Read list of Children terms from onboard "cache" in the resource properties field.
     * Sample of the object structure might look like this:
     * "children": {
     *      "243": {"alias": "earrings", "pagetitle": "Earrings", "published": true, "menuindex": 0, "children": []},
     *      "244": {"alias": "necklaces", "pagetitle": "Necklaces", "published": true, "menuindex": 0, "children": []},
     *      "249": {"alias": "seasonal", "pagetitle": "Seasonal", "published": true, "menuindex": 0, "children": []},
     *      "250": {
     *          "alias": "sale",
     *          "pagetitle": "Sale",
     *          "published": true,
     *          "menuindex": 0,
     *          "children": {
     *              "453": {
     *              "alias": "other-sale",
     *              "pagetitle": "Other Sale",
     *              "published": true,
     *              "menuindex": 0,
     *              "children": []
     *      }
     * }
     */
    public function getChildren()
    {
        $properties = $this->get('properties');
        return $this->xpdo->getOption('children', $properties, array());
    }

    /**
     * Remember: the children here represent ANY descendant, not just the immediate children.  This is so we can see quickly and without
     * multiple queries which are the children terms.
     * See Also http://rtfm.modx.com/revolution/2.x/developing-in-modx/other-development-resources/class-reference/modx/modx.getchildids
     * @return array simple array of page ids (i.e. term ids)
     */
    public function getChildrenIds()
    {
        $properties = $this->get('properties');
        $term_ids = $this->xpdo->getOption('children_ids', $properties, array());
        if ($term_ids) {
            return array_keys($term_ids);
        }
        return array();
    }

    public static function getControllerPath(xPDO &$modx)
    {
        $x = $modx->getOption('taxonomies.core_path', null, $modx->getOption('core_path') . 'components/taxonomies/') . 'controllers/term/';
        return $x;
    }

    public function getContextMenuText()
    {
        $this->xpdo->lexicon->load('taxonomies:default');
        return array(
            'text_create' => $this->xpdo->lexicon('term'),
            'text_create_here' => $this->xpdo->lexicon('term_create_here'),
        );
    }

    public function getResourceTypeName()
    {
        $this->xpdo->lexicon->load('taxonomies:default');
        return $this->xpdo->lexicon('term');
    }

    /**
     * This runs each time the tree is drawn.
     * @param array $node
     * @return array
     */
    public function prepareTreeNode(array $node = array())
    {
        $this->xpdo->lexicon->load('taxonomies:default');
        $menu = array();
        $idNote = $this->xpdo->hasPermission('tree_show_resource_ids') ? ' <span dir="ltr">(' . $this->id . ')</span>' : '';

        // System Default
        $template_id = $this->getOption('taxonomies.default_term_template');
        // Or, see if this Taxonomy node sets its own default...
        $container = $this->xpdo->getObject('modResource', $this->id);
        if ($container) {
            $props = $container->get('properties');
            if ($props) {
                if (isset($props['taxonomy']['default_template']) && !empty($props['taxonomy']['default_template'])) {
                    $template_id = $props['taxonomy']['default_template'];
                }
            }
        }
        $menu[] = array(
            'text' => '<b>' . $this->get('pagetitle') . '</b>' . $idNote,
            'handler' => 'Ext.emptyFn',
        );
        $menu[] = '-'; // equiv. to <hr/>
        $menu[] = array(
            'text' => $this->xpdo->lexicon('term_create_here'),
            'handler' => "function(itm,e) { 
				var at = this.cm.activeNode.attributes;
		        var p = itm.usePk ? itm.usePk : at.pk;
	
	            Ext.getCmp('modx-resource-tree').loadAction(
	                'a='+MODx.action['resource/create']
	                + '&class_key=Term'
	                + '&parent='+p
	                + '&template=" . $template_id . "'
	                + (at.ctx ? '&context_key='+at.ctx : '')
                );
        	}",
        );
        $menu[] = array(
            'text' => $this->xpdo->lexicon('term_duplicate'),
            'handler' => 'function(itm,e) { itm.classKey = "Term"; this.duplicateResource(itm,e); }',
        );
        $menu[] = '-';
        if ($this->get('published')) {
            $menu[] = array(
                'text' => $this->xpdo->lexicon('term_unpublish'),
                'handler' => 'this.unpublishDocument',
            );
        } else {
            $menu[] = array(
                'text' => $this->xpdo->lexicon('term_publish'),
                'handler' => 'this.publishDocument',
            );
        }
        if ($this->get('deleted')) {
            $menu[] = array(
                'text' => $this->xpdo->lexicon('term_undelete'),
                'handler' => 'this.undeleteDocument',
            );
        } else {
            $menu[] = array(
                'text' => $this->xpdo->lexicon('term_delete'),
                'handler' => 'this.deleteDocument',
            );
        }
        $menu[] = '-';
        $menu[] = array(
            'text' => $this->xpdo->lexicon('term_view'),
            'handler' => 'this.preview',
        );

        $node['menu'] = array('items' => $menu);
        $node['hasChildren'] = true;
        return $node;
    }

    /**
     * Remove this term's id from the parent hierarchy
     * @param array $ancestors
     * @return mixed
     */
    public function remove(array $ancestors = array())
    {

        $term_id = $this->get('id');
        $parent_id = $this->get('parent');
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Start of removing Term: ' . $term_id .' (Child of '.$parent_id.')', '', __CLASS__, basename(__FILE__), __LINE__);
        $Parent = $this->xpdo->getObject('modResource', $parent_id);

        //$result = parent::remove($ancestors);

        if (!$Parent) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Parent of Term '.$term_id.' not found' , '', __CLASS__, basename(__FILE__), __LINE__);
            //die();
            return parent::remove($ancestors); // End of the Line
        }
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Removing Term: ' . $term_id . ': Updating the parent (' . $Parent->get('id') . ')', '', __CLASS__, basename(__FILE__), __LINE__);

        $parent_props = $Parent->get('properties');
        if (!isset($parent_props['children'])) {
            $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Failed to remove the term because the parent did not have a "children" property (???) (' . $Parent->get('id') . ')', '', __CLASS__, basename(__FILE__), __LINE__);
            return parent::remove($ancestors); // uh... not sure why the parent wouldn't have this
        }
        // Remove this term from the parent
        unset($parent_props['children'][$term_id]);
        unset($parent_props['children_ids'][$term_id]);
        $Parent->set('properties',$parent_props);

        $result = $Parent->save(); // Ripple up
        if (!$result)
        {
            $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Failed to update parent.', '', __CLASS__, basename(__FILE__), __LINE__);
        }
        return parent::remove($ancestors);
    }

    /**
     * We override/enhance the parent save() operation so we can cache the
     * hierarchical data in there as the terms are manipulated.
     *
     * properties is rendered as JSON -- see notes @ top of class for structure.
     *
     * Updating a term triggers a ripple UP the tree.
     * Moving a term up/down in the hierarchy forces an unsetting in prev_parent
     */
    public function save($cacheFlag = null)
    {

        $properties = $this->get('properties');
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Term: ' . $this->get('id') . ' properties: ' . print_r($properties, true), '', __CLASS__, basename(__FILE__), __LINE__);
        $fingerprint = $this->xpdo->getOption('fingerprint', $properties); // the old one
        $prev_parent = $this->xpdo->getOption('prev_parent', $properties, $this->get('parent'));
        $children = $this->xpdo->getOption('children', $properties, array());
        $children_ids = $this->xpdo->getOption('children_ids', $properties, array());
        $properties['fingerprint'] = $this->calcFingerprint(); // the new one
        $properties['prev_parent'] = $this->get('parent');
        $this->set('properties', $properties);
        $rt = parent::save($cacheFlag); // <-- first, do the normal save (this ensures we have a term id)

//        if ($this->isNew()) {
//            $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Is New');
//        }
        // Compare the old to the new (this should only be able to match when a term is updated; new terms should not have matching prints)
        if ($fingerprint == $properties['fingerprint']) {
            // Nothing changed
            $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Term: ' . $this->get('id') . ': Fingerprint unchanged.', '', __CLASS__, basename(__FILE__), __LINE__);
            //$rt = parent::save($cacheFlag);
            return $rt; // nothing to do
        }

        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'New Fingerprint detected for this term: ' . $this->get('id'), '', __CLASS__, basename(__FILE__), __LINE__);

        /* 
        Has this term moved?  Run unset on the prev_parent to remove this term as a child.
        
        E.g. Terms Hiearchy like this:
            Cat. (taxonomy)
                  A 
                  B -> C
        
         Changes when C is moved over to become a child of A:
            Cat. (taxonomy)
                  A -> C
                  B
        
         In this case, the rippling action would update A (see below) b/c it "knows" 
         that it had a new term added, but there is no "onRemove" event where B would "know"
         that a child term was removed.  So we fake the "onRemove" behavior by storing/caching
         a "prev_parent" attribute in the properties and checking it against the the current parent
         to see if we need to go clean up the "children" attributes on the prev_parent.
         
         In this example, "onRemove" would first remove C as a child from B & Cat.
         Then later (below), the properties of the new parent are updated, adding C as a child of A
         and RE-adding C as a child of Cat.
        */
        if ($prev_parent != $this->get('parent')) {
            $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Term: ' . $this->get('id') . ' - Move in the hierarchy detected from ' . $prev_parent . ' to ' . $this->get('parent'), '', __CLASS__, basename(__FILE__), __LINE__);
            $PrevParent = $this->xpdo->getObject('modResource', $prev_parent);
            if ($PrevParent) {
                $prev_parent_props = $PrevParent->get('properties');
                unset($prev_parent_props['children'][$this->get('id')]);
                unset($prev_parent_props['children_ids'][$this->get('id')]);
                $PrevParent->set('properties', $prev_parent_props);
                if (!$PrevParent->save()) { // <-- this may ripple up, 
                    $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, $this->get('id') . ': Error saving previous parent ' . $prev_parent, '', __CLASS__, basename(__FILE__), __LINE__);
                }
            }
        }
        // New/Existing Parent
        $Parent = $this->xpdo->getObject('modResource', $this->get('parent'));
        if (!$Parent) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Parent not found!', $this->get('id'), __CLASS__, basename(__FILE__), __LINE__);
            return $rt; // nothing we can do
        }
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Term: ' . $this->get('id') . ': Updating the parent (' . $this->get('parent') . ')', '', __CLASS__, basename(__FILE__), __LINE__);

        $parent_props = $Parent->get('properties');

        // Children may be out of date by this point        
        $parent_props['children'][$this->get('id')] = array(
            'alias' => $this->get('alias'),
            'pagetitle' => $this->get('pagetitle'),
            'published' => $this->get('published'),
            'menuindex' => $this->get('menuindex'),
            'children' => $children // out of date?
        );
        // The children ids should ripple up: each page pushes its own id on the parent's stack
        // You can't just set the appropriate key in the parent's 'children_ids' array: that will not
        // ripple up hierarchically, it will only affect the immediate parent.  So we piggy back on the
        // 'children' property, which does obey a deep hierarchy.
        //$parent_props['children_ids'][$this->get('id')] = true;
        $parent_props['children_ids'] = $this->flattenChildrenObjectsToIds($parent_props['children'], $children_ids);
        $this->xpdo->log(xPDO::LOG_LEVEL_DEBUG, 'Setting properties of parent page (' . $Parent->get('id') . '): ' . print_r($parent_props, true), '', __CLASS__, basename(__FILE__), __LINE__);
        $Parent->set('properties', $parent_props);

        return $Parent->save();
    }


}

//------------------------------------------------------------------------------
//! CreateProcessor
//------------------------------------------------------------------------------
class TermCreateProcessor extends modResourceCreateProcessor
{

    public $object;

    /**
     * Override modResourceCreateProcessor::afterSave to force certain attributes.
     * @return boolean
     */
    public function afterSave()
    {
        $this->object->set('class_key', 'Term');
        $this->object->set('cacheable', true);
        $this->object->set('isfolder', true);
        return parent::afterSave();
    }


}

class TermUpdateProcessor extends modResourceUpdateProcessor
{
}