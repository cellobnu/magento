<?php

class DB1_AnyMarket_Helper_Category extends DB1_AnyMarket_Helper_Data
{

    protected $arrJSON = array();
    protected $arrDelCat = array();
    protected $arrNewCateg = array();


    /**
     * export all category to AnyMarket
     *
     * @param $storeID
     */
    public function exportCategories($storeID){
        $rootCategoryId = Mage::app()->getStore($storeID)->getRootCategoryId();
        if($rootCategoryId == 0){
            $categories = Mage::getModel('catalog/category')
                ->getCollection()
                ->setStoreId($storeID)
                ->addFieldToFilter('is_active', 1)
                ->addAttributeToSelect('*');
        }else{
            $categories = Mage::getModel('catalog/category')
                ->getCollection()
                ->setStoreId($storeID)
                ->addFieldToFilter('is_active', 1)
                ->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"))
                ->addAttributeToSelect('*');
        }

        $arrParents = array();
        $fItem = true;
        foreach ($categories as $category) {
            $parentID = $category->getParentId();
            $id = $category->getId();
            $intAM = $category->getData('categ_integra_anymarket');

            if($fItem && $intAM == 0){
                continue;
            }
            $fItem = false;

            if($intAM == 1){
                $IdParent = null;
                $intAMParent = 1;
                if(isset($arrParents[$parentID])){
                    $IdParent = $arrParents[ $parentID ][0];
                    $intAMParent = $arrParents[ $parentID ][1];
                }

                if($intAMParent == 1){
                    $categRet = $this->exportSpecificCategory($category, $IdParent, $storeID);
                    if($categRet != null){
                        $arrParents[ $id ] = array($categRet[0], $intAM);
                    }
                }
            }else{
                $arrParents[ $id ] = array($id, 0);
            }

        }

    }

    /**
     * Delete Specific Category Recursively
     *
     * @param $category
     * @param $storeID
     */
    public function deleteCategs($category, $storeID){
        $this->arrDelCat = array();
        array_push($this->arrDelCat, $category->getId());
        $this->deleteCategRecursively($category, $storeID);

        foreach ( array_reverse($this->arrDelCat) as $categ ) {
            $_category = Mage::getModel('catalog/category')->load($categ);
            Mage::helper('db1_anymarket/category')->deleteSpecificCategory($_category, $storeID);
        }
        
    }

    /**
     * Delete Specific Category Recursively
     *
     * @param $category
     * @param $storeID
     */
    public function deleteCategRecursively($category, $storeID){
        $subcats = $category->getChildren();
        if($subcats != ''){
            foreach(explode(',',$subcats) as $subCatid){
                $_category = Mage::getModel('catalog/category')->load($subCatid);

                array_push($this->arrDelCat, $subCatid);
                if($_category->getChildren() != ''){
                    $this->deleteCategRecursively($_category, $storeID);
                }
            }
        }
    }

    /**
     * Delete Specific Category
     *
     * @param $category
     * @param $storeID
     */
    public function deleteSpecificCategory($category, $storeID){
        $anymarketcategories = Mage::getModel('db1_anymarket/anymarketcategories')->load($category->getId(), 'nmc_id_magento');
        if( $anymarketcategories->getData('nmc_cat_id') != '' ){
            $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
            $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

            $headers = array( 
                "Content-type: application/json",
                "Accept: */*",
                "gumgaToken: ".$TOKEN
            );

            $returnDelCat = $this->CallAPICurl("DELETE", $HOST."/v2/categories/".$anymarketcategories->getData('nmc_cat_id'), $headers, null);

            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            if($returnDelCat['error'] == '1'){
                $anymarketlog->setLogDesc( 'Error on delete category ('.$category->getName().') - '.is_string($returnDelCat['return']) ? $returnDelCat['return'] : json_encode($returnDelCat['return']) );
            }else{
                $anymarketlog->setLogDesc( 'Deleted category ('.$category->getName().')' );
                $anymarketcategories->delete();
            }
            $anymarketlog->setStatus("1");
            $anymarketlog->setStores(array($storeID));
            $anymarketlog->save();
        }
    }

    /**
     *  Export Specific Category Recursively
     *
     * @param $category
     * @param $storeID
     */
    public function exportCategRecursively($category, $storeID){
        $subcats = $category->getChildren();
        foreach(explode(',',$subcats) as $subCatid){
            $_category = Mage::getModel('catalog/category')->load($subCatid);
            if($_category->getData('categ_integra_anymarket') == 1){
                Mage::helper('db1_anymarket/category')->exportSpecificCategory($_category, $category->getId(), $storeID);

                if($_category->getChildren() != ''){
                    $this->exportCategRecursively($_category, $storeID);
                }
            }
        }
    }

    /**
     * Export Specific Category
     *
     * @param $category
     * @param $IdParent
     * @param $storeID
     * @return array|null
     */
    public function exportSpecificCategory($category, $IdParent, $storeID){
        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array( 
            "Content-type: application/json",
            "Accept: */*",
            "gumgaToken: ".$TOKEN
        );

        $parentID = $category->getParentId();
        $id = $category->getId();
        $name = $category->getName();

        $JSON = array(
            "name" => $name,
            "partnerId" => $id,
            "parent" => array("id" => $IdParent),
            "calculatedPrice" => true
        );

        $anymarketcategories = Mage::getModel('db1_anymarket/anymarketcategories')->load($category->getId(), 'nmc_id_magento');
        if( $anymarketcategories->getData('nmc_cat_id') == '' ){

            if($parentID){
                $amCatPar = Mage::getModel('db1_anymarket/anymarketcategories')->load($parentID, 'nmc_id_magento');
                $IdParent = $amCatPar->getData('nmc_cat_id');
                $JSON["parent"] = array("id" => $IdParent);
            }

            $returnCat = $this->CallAPICurl("POST", $HOST."/v2/categories/", $headers, $JSON);

            if($returnCat['error'] == '1'){
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Error on export category ('.$name.') - '.is_string($returnCat['return']) ? $returnCat['return'] : json_encode($returnCat['return']) );
                $anymarketlog->setLogJson($returnCat['json']);
                $anymarketlog->setStatus("1");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();

                return null;
            }else{
                $JSONReturn = $returnCat['return'];

                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Category successfully exported ('.$name.')' );
                $anymarketlog->setLogJson($returnCat['json']);
                $anymarketlog->setStatus("1");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();

                $anymarketcategories = Mage::getModel('db1_anymarket/anymarketcategories');
                $anymarketcategories->setNmcCatId( $JSONReturn->id );
                $anymarketcategories->setNmcCatRootId( $IdParent != null ? $IdParent : '000' );
                $anymarketcategories->setNmcCatDesc( $name );
                $anymarketcategories->setNmcIdMagento( $id );
                $anymarketcategories->setStatus('1');
                $anymarketcategories->save();

                return array($JSONReturn->id);
            }
        }else{
            $returnCatPUT = $this->CallAPICurl("PUT", $HOST."/v2/categories/".$anymarketcategories->getNmcCatId(), $headers, $JSON);

            if($returnCatPUT['error'] == '1'){
                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Error on update category ('.$name.') - '.is_string($returnCatPUT['return']) ? $returnCatPUT['return'] : json_encode($returnCatPUT['return']) );
                $anymarketlog->setLogJson($returnCatPUT['json']);
                $anymarketlog->setStatus("1");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();

                return null;
            }else{
                $JSONReturn = $returnCatPUT['return'];

                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Category successfully updated ('.$name.')' );
                $anymarketlog->setLogJson($returnCatPUT['json']);
                $anymarketlog->setStatus("1");
                $anymarketlog->setStores(array($storeID));
                $anymarketlog->save();

                $anymarketcategories->setNmcCatDesc( $name );
                $anymarketcategories->save();

                return array($JSONReturn->id);
            }
        }
    }

    // ------ ANYMARKET
    /**
     * get all root category of AM
     */
    public function getCategories(){
        $storeID = $this->getCurrentStoreView();
        $HOST  = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_host_field', $storeID);
        $TOKEN = Mage::getStoreConfig('anymarket_section/anymarket_acesso_group/anymarket_token_field', $storeID);

        $headers = array( 
            "Content-type: application/json",
            "Accept: */*",
            "gumgaToken: ".$TOKEN
        );

        $startRec = 0;
        $countRec = 1;
        $arrOrderCod = null;
        $this->arrNewCateg = array();
        while ($startRec <= $countRec) {
            $returnCat = $this->CallAPICurl("GET", $HOST."/rest/api/v2/categories/?offset=".$startRec."&limit=30", $headers, null);

            if($returnCat['error'] == '1'){
                $startRec = 1;
                $countRec = 0;

                $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
                $anymarketlog->setLogDesc( 'Error on Sincronize Category '. $returnCat['return'] );
                $anymarketlog->setStatus("1");
                $anymarketlog->save();
            }else{
                $CatJSON = $returnCat['return'];

                $startRec = $startRec + $CatJSON->page->size;
                $countRec = $CatJSON->page->totalElements;

                foreach ($CatJSON->content as $category) {
                    $IDCat = $category->id;
                    array_push($this->arrNewCateg, $IDCat);
                    $anymarketcategoriesUpdt = Mage::getModel('db1_anymarket/anymarketcategories')->load($IDCat, 'nmc_cat_id');
                    if($anymarketcategoriesUpdt->getData('nmc_cat_id') == null){
                        $anymarketcategories = Mage::getModel('db1_anymarket/anymarketcategories');
                        $anymarketcategories->setNmcCatId( $IDCat );
                        $anymarketcategories->setNmcCatRootId( '000' );
                        $anymarketcategories->setStatus('1');
                        $anymarketcategories->setNmcCatDesc( $category->name );
                        $anymarketcategories->setStores(array($storeID));
                        $anymarketcategories->save();
                    }else{
                        $anymarketcategoriesUpdt->setNmcCatDesc( $category->name );
                        $anymarketcategoriesUpdt->save();
                    }

                    $this->getChildCat($HOST, $headers, $category->id, $IDCat, $storeID);
                }

            }

        }
/*
        if(!empty($this->arrNewCateg) ){
            $allCategs = Mage::getModel('db1_anymarket/anymarketcategories')->getCollection();

            foreach ($allCategs as $categ) {
                if( !in_array($categ->getData('nmc_cat_id'), $this->arrNewCateg) ){
                    $categ->delete();
                }
            }
        }
*/
    }

    /**
     * get all child category of AM
     *
     * @param $HOST
     * @param $headers
     * @param $catID
     * @param $IDCatRoot
     * @param $id_store
     */
    private function getChildCat($HOST, $headers, $catID, $IDCatRoot, $id_store){
        $returnCatSpecific = $this->CallAPICurl("GET", $HOST."/rest/api/v2/categories/".$catID, $headers, null);
        $CatSpecifivJSON = $returnCatSpecific['return'];
        if($returnCatSpecific['error'] == '0'){
            if( isset($CatSpecifivJSON->children) ){
                foreach ($CatSpecifivJSON->children as $catChild) {

                    array_push($this->arrNewCateg, $catChild->id);
                    $anymarketcategoriesUpdt = Mage::getModel('db1_anymarket/anymarketcategories')->load($catChild->id, 'nmc_cat_id');
                    if($anymarketcategoriesUpdt->getData('nmc_cat_id') == null){
                        $anymarketcategories = Mage::getModel('db1_anymarket/anymarketcategories');
                        $anymarketcategories->setNmcCatId( $catChild->id );
                        $anymarketcategories->setNmcCatRootId( $IDCatRoot );
                        $anymarketcategories->setStatus('1');
                        $anymarketcategories->setNmcCatDesc( $catChild->name );
                        $anymarketcategories->setStores(array($id_store));
                        $anymarketcategories->save();
                    }else{
                        $anymarketcategoriesUpdt->setNmcCatDesc( $catChild->name );
                        $anymarketcategoriesUpdt->save();
                    }

                    $this->getChildCat($HOST, $headers, $catChild->id, $catChild->id, $id_store);
                }
            }
        }else{
            $anymarketlog = Mage::getModel('db1_anymarket/anymarketlog');
            $anymarketlog->setLogDesc( $returnCatSpecific['return'] );
            $anymarketlog->setLogId( $IDCatRoot ); 
            $anymarketlog->setStatus("1");
            $anymarketlog->save();
        }
    }


}