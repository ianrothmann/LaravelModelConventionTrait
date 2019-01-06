<?php

namespace IanRothmann\Database\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Focus\ModelConvention
 *
 * @mixin \Eloquent
 */
trait ModelConvention
{

    public function __construct(array $attributes = []){
        parent::__construct($attributes);
        $this->table=strtolower(substr(strrchr(get_class($this), '\\'), 1));
        if(!isset($this->primaryKey)||$this->primaryKey=='id')
            $this->primaryKey=$this->table.'id';
    }

    public function primaryKey($model=''){
        if($model=='')
            $model=get_class($this);
        return strtolower(substr(strrchr($model, '\\'), 1)).'id';
    }

    function getAppNamespace()
    {
        $composer = json_decode(file_get_contents(base_path().'/composer.json'), true);
        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path)
        {
            foreach ((array) $path as $pathChoice)
            {
                if (realpath(app_path()) == realpath(base_path().'/'.$pathChoice)) return $namespace;
            }
        }
        throw new \Exception("Unable to detect application namespace.");
    }

    private function parseRelated($related){
        if(!class_exists($related)){
            $app=$this->getAppNamespace();
            if(strpos($related,$app)!==FALSE)
                return $related;

            if(!class_exists($app.'Models\\'.$related)){
                return $app.$related;
            }else{
                return $app.'Models\\'.$related;
            }
        }else{
              return $related;
        }
    }

    /**
     * @param $related
     * @param null $foreignKey
     * @param null $otherKey
     * @param null $relation
     * @return mixed
     */
    public function belongsTo($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        $related=$this->parseRelated($related);

        if($foreignKey==null||$otherKey==null){
            $key=$this->primaryKey($related);
            if($foreignKey==null)
                $foreignKey=$key;
            if($otherKey==null)
                $otherKey=$key;
        }

        return parent::belongsTo($related, $foreignKey, $otherKey, $relation);
    }

    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $related=$this->parseRelated($related);

        if($foreignKey==null)
            $foreignKey=$this->primaryKey;

        if($localKey==null)
            $localKey=$this->primaryKey;

        return parent::hasMany($related, $foreignKey, $localKey);
    }

    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $related=$this->parseRelated($related);

        if($foreignKey==null)
            $foreignKey=$this->primaryKey;

        if($localKey==null)
            $localKey=$this->primaryKey;

        return parent::hasOne($related, $foreignKey, $localKey);
    }

    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
                                  $parentKey = null, $relatedKey = null, $relation = null)
    {

        $related=$this->parseRelated($related);
        if($foreignPivotKey==null){
            $foreignPivotKey=$this->primaryKey;
        }

        if($relatedPivotKey==null) {
            $relatedPivotKey = $this->primaryKey($related);
        }
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if (is_null($table)) {
            $table = $this->joiningTable($related);
        }

        return new BelongsToMany(
            $instance->newQuery(), $this, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(), $relation
        );
    }


    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $related=$this->parseRelated($related);
        $through=$this->parseRelated($through);
        if($firstKey==null)
            $firstKey=$this->primaryKey;

        if($secondKey==null)
            $secondKey=$this->primaryKey($through);

        if($localKey==null)
            $localKey=$this->primaryKey;
        return parent::hasManyThrough($related, $through, $firstKey, $secondKey, $localKey,$secondLocalKey);
    }

    function allWithHas($relationship_name,$addQuery=null,$pivotCols=[],$withTrashed=false){
        if(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\BelongsToMany')){

            $props=$this->accessProtected($this->$relationship_name(),['table','foreignKey','relatedKey','query']);
            $props_q=$this->accessProtected($props['query'],['model']);
            $props_t=$this->accessProtected($props_q['model'],['table','primaryKey']);

            $query=$this->query();
            $join='rightJoin';

            $model=$props_q['model'];
            $fk=$props['foreignKey'];
            $this_table=$this->table;
            $this_fk=$this->$fk;

            $query->$join($props['table'],$this->table.'.'.$props['foreignKey'],'=',$props['table'].'.'.$props['foreignKey'])
                ->$join($props_t['table'],function($join)use($props_t,$props,$fk,$this_table,$this_fk){
                    $join->on($props_t['table'].'.'.$props_t['primaryKey'],'=',$props['table'].'.'.$props['relatedKey']);
                    $join->where(function($query) use ($props,$fk,$this_table,$this_fk){
                        $query->where($this_table.'.'.$props['foreignKey'],$this_fk)
                            ->orWhereNull($this_table.'.'.$props['foreignKey']);
                    });
                });

            $pivots='';
            foreach ($pivotCols as $pivot) {
                $pivots.='`'.$props['table'].'`.`'.$pivot.'`,';
            }


            $query->select(DB::raw('`'.$props_t['table'].'`.*,'.$pivots.' `'.$this->table.'`.`'.$this->primaryKey.'` is not null as `has`'));
            //
            if(is_callable($addQuery)){
                $addQuery($query);
            }

            if (!$withTrashed&&in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model))){
                $query->whereNull($model->table.'.deleted_at');

            }


            return $model::hydrate($query->get()->toArray());

        }else{
            throw new \Exception("Must be a BelongsToMany");
        }

    }

    function scopeJoinRelation($query,$relationship_name,$join=''){
        if($join=='')
            $join='join';
        elseif ($join=='left')
            $join='leftJoin';
        elseif ($join=='right')
            $join='rightJoin';

        if(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\BelongsToMany')){
            $reflect=$this->accessProtected($this->$relationship_name(),['table','foreignPivotKey','parentKey','relatedKey','related','parent']);
            $reflect_related=$this->accessProtected($reflect['related'],['table']);
            $reflect_this=$this->accessProtected($reflect['parent'],['table']);

            $pivotTable=$reflect['table'];
            $relatedTable=$reflect_related['table'];
            $relatedKey=$reflect['relatedKey'];
            $thisTable=$reflect_this['table'];
            $foreignPivotKey=$reflect['foreignPivotKey'];
            $parentKey=$reflect['parentKey'];

            return $query->$join($pivotTable,$thisTable.'.'.$parentKey,'=',$pivotTable.'.'.$foreignPivotKey)
                ->$join($relatedTable,$relatedTable.'.'.$relatedKey,'=',$pivotTable.'.'.$relatedKey);

        }elseif(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\HasMany')){
            $alias=strtolower($relationship_name);
            $reflect=$this->accessProtected($this->$relationship_name(),['localKey','foreignKey','parent','related']);
            $reflect_related=$this->accessProtected($reflect['related'],['table']);
            $relatedKey=$alias.strstr($reflect['foreignKey'],'.');

            return $query->$join($reflect_related['table'].' as '.$alias,$this->table.'.'.$reflect['localKey'],'=',$relatedKey);

        }elseif(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\HasOne')){
            $alias=strtolower($relationship_name);
            $reflect=$this->accessProtected($this->$relationship_name(),['localKey','foreignKey','parent','related']);
            $reflect_related=$this->accessProtected($reflect['related'],['table']);
            $relatedKey=$alias.strstr($reflect['foreignKey'],'.');

            return $query->$join($reflect_related['table'].' as '.$alias,$this->table.'.'.$reflect['localKey'],'=',$relatedKey);

        }elseif(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\BelongsTo')){
            $alias=strtolower($relationship_name);

            $reflect=$this->accessProtected($this->$relationship_name(),['foreignKey','ownerKey','related']);
            $reflect_related=$this->accessProtected($reflect['related'],['table','primaryKey']);
            return $query->$join($reflect_related['table'].' as '.$alias,$alias.'.'.$reflect['ownerKey'],'=',$this->table.'.'.$reflect['foreignKey']);
        }elseif(is_a($this->$relationship_name(),'Illuminate\Database\Eloquent\Relations\HasManyThrough')){
            $reflect=$this->accessProtected($this->$relationship_name(),['firstKey','secondKey','localKey','throughParent','related']);
            $reflect_related=$this->accessProtected($reflect['related'],['table']);
            $reflect_through=$this->accessProtected($reflect['throughParent'],['table','primaryKey']);
            $thisKey=$reflect['localKey'];
            $throughKey=$reflect['firstKey'];
            $throughPrimaryKey=$reflect_through['primaryKey'];
            $relatedKey=$reflect['secondKey'];
            $throughTable=$reflect_through['table'];
            $relatedTable=$reflect_related['table'];
            return $query->$join($throughTable,$throughTable.'.'.$throughKey,'=',$this->table.'.'.$thisKey)
                ->$join($relatedTable,$relatedTable.'.'.$relatedKey,'=',$throughTable.'.'.$throughPrimaryKey);

        }

        return $query;
    }

    private function accessProtected($obj, $propArr=[]) {
        $reflection = new \ReflectionClass($obj);
        $result=[];
        foreach ($propArr as  $prop){
            $property = $reflection->getProperty($prop);
            $property->setAccessible(true);
            $result[$prop]=$property->getValue($obj);
        }

        return $result;
    }
}
