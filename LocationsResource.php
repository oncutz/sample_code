<?php

namespace app\api\resources;

use Yii;
use app\models\Locations;
use app\api\resources\LocationsDetailsResource;
use DateTime;
use Exception;

class LocationsResource extends Locations
{
    private $errors = [];

    public function extraFields()
    {
          return [ 
            'company', 
            'machine', 
            'contact',
            'stock',
            'income',
            'lastService'
            ];
    }

    public static function find()
    {
        $childIds = AdminResource::findChildIds(Yii::$app->user->id);
        $query = new \app\models\query\LocationsQuery(get_called_class());
        return $query->andWhere(['user_id' => $childIds]);
    }

    public function getLocationsDetails()
    {
        return $this->hasMany(LocationsDetailsResource::className(), ['location_id' => 'id']);
    }

    public function getMachine()
    {
        $result  = $this->hasMany(MachinesResource::className(), ['id' => 'machine_id'])->via('locationsDetails')->all();
        $product = $this->getProduct();
        $stock   = $this->getStock();
        //we add to the initial search records the index 'product' and 'stock' to compress all the informations regarding the machine in one array index
        foreach($result as $key => $value){
            $result[$key] = $value->toArray();
            $result[$key]['product']['id'] = $product[$key]['product']['id'];
            $result[$key]['product']['name'] = $product[$key]['product']['name'];
            $result[$key]['stock'] = $stock[$key]['stockPerMachine']['quantity'];
        }
        
      return $result;
    }

    public function getCompany()
    {
        return $this->hasOne(CompanyResource::className(), ['id' => 'company_id'])->via('locationsDetails');
    }

    public function getContact()
    {
        return $this->hasOne(ContactsResource::className(), ['id' => 'contact_id'])->via('locationsDetails');
    }

    public function getStock()
    {
        $items = $this->getLocationsDetails()->all();
      
        foreach($items as $key => $value) {
            $items[$key] = $value->toArray([], ['stockPerMachine']);
        }
        
        return $items;
    }

    public function getProduct()
    {
        $items = $this->getLocationsDetails()->all();
      
        foreach($items as $key => $value) {
            $items[$key] = $value->toArray([], ['product']);
        }
        
        return $items;
    }

    public function getIncome()
    {
        return $this->hasMany(IncomeResource::className(), ['location_id' => 'id'])->select('id, value, date');
    }

    public function createLocation($params)
    {
        if($this->load($params, '') && $this->save()) {
            return true;
        } else {
            return false;
        }
    

    }

    
}
