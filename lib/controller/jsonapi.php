<?php
namespace Controller;

/**
 * JsonApi is a RESTful controller that takes care of one model
 * It can (and must) be extended by each needed controller (as many as there are models, ususally).
 * Children can override any BaseController method, but the only required one is the constructor,
 * where the basic data is set up.
 */
class JsonApi {
    /**
     * All children must override __construct and call their parent::_construct
     * @model String with the related model for this controller
     * @blacklist Array of fields that must not be shown/returned (ie, 'password')
     */
    public function __construct($model="", $blacklist = []) {
        $this->model = $model;
        $this->blacklist = $blacklist;
    }

    /* RESTful API methods */
    public function getOne($f3, $params) {
        $id = intval($params['id']);

        // We don't support includes, for the time being
        if(isset($f3["GET.include"]))
            $f3->error(400, "Include is not yet implemented");

        $model = $this->getModel();
        $query = ["id=?", $id];
        $query = $this->processSingleQuery($query); // Allows customization in children
        $model->load($query);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }

        echo $this->oneToJson($model);
    }

    public function getList($f3) {
        $model = $this->getModel();
        // Query creation
        $query = [""];
        $query = $this->processListQuery($query); // Allows customization in children
        // We don't support includes, for the time being
        if(isset($f3["GET.include"]))
            $f3->error(400, "Include is not yet implemented");
        // Here we evaluate a few common filters, if any exist.
        if(isset($f3["GET.filter"])) {
            $filters = $f3["GET.filter"];
            $fields = array_keys($object->getFieldConfiguration());
            // We want: field equals, field from, field to, field not equals.
            foreach($filters as $filter=>$value) {
                if(in_array($filter, $fields)) { // equals
                    $vals = explode(',', $value);
                    $filter_query = implode(" OR ", array_fill(0, count($vals), "`$filter` = ?"));
                    if(count($query) > 1)
                        $query[0].= " AND ";
                    $query[0].= "($filter_query)";
                    $query = array_merge($query, $vals);
                } else if(substr($filter, -4) == "_not" && in_array(substr($filter, 0, -4), $fields)) { // not equals
                    $vals = explode(',', $value);
                    $filter_query = implode(" OR ", array_fill(0, count($vals), "`$filter` <> ?"));
                    if(count($query) > 1)
                        $query[0].= " AND ";
                    $query[0].= "($filter_query)";
                    $query = array_merge($query, $vals);
                } else if(substr($filter, -5) == "_from" && in_array(substr($filter, 0, -5), $fields)) { // larger than
                    // There should only be one value in this case, so no explodes and fills and so
                    if(count($query) > 1)
                        $query[0].= " AND ";
                    $query[0].= "(`$filter` >= ?)";
                    $query[] = $value;
                } else if(substr($filter, -3) == "_to" && in_array(substr($filter, 0, -3), $fields)) { // smaller than
                    // There should only be one value in this case, so no explodes and fills and so
                    if(count($query) > 1)
                        $query[0].= " AND ";
                    $query[0].= "(`$filter` <= ?)";
                    $query[] = $value;
                }
            }
        }
        // Here we should add sorting, if requested
        if(isset($f3["GET.sort"]))
            $f3->error(400, "Sorting is not yet implemented");
        // Here we should paginate, if requested
        if(isset($f3["GET.page"])) {
        }
        $list = $model->find($query);

        echo $this->manyToJson($list);
    }

    public function create($f3) {
        $model = $this->getModel();
        echo $this->save($f3, $model);
    }

    public function update($f3, $params) {
        $id = intval($params['id']);
        $model = $this->getModel();
        $model->load(["id=?", $id]);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }
        echo $this->save($f3, $model);
    }

    public function delete($f3, $params) {
        $id = intval($params['id']);
    }

    public function relationships($f3, $params) {
        $id = intval($params['id']);
        $relationship = $params['relationship'];
    }

    public function related($f3, $params) {
        $id = intval($params['id']);
        $related = $params['related'];
    }

    /* Helper methods */

    protected function save($f3, $obj) {
        $vars = json_decode($f3->BODY, true); // 'true' makes it an array (to avoid issues with dashed names)
        $vars = $this->processInput($vars, $obj); 
        $valid_fields = array_keys($obj->cast(null, 0));
        // Update all standard fields
        foreach($valid_fields?:[] as $key) {
            if( !empty($vars[$key]) 
                || $obj->fieldConf[$key]["nullable"]) {
                $obj->$key = $vars[$key];
            }
        }
        // Update relationships
        foreach($vars["relationships"]?:[] as $rel => $data) {
            if(in_array($rel, $valid_fields)) {
                if(is_array($data['data'])) {
                    $rels = [];
                    foreach($data['data']?:[] as $entry) {
                        $rels[] = intval($entry['id']);
                    }
                    $obj->$rel = $rels;
                } else {
                    $obj->$rel = intval($data['data']['id']);
                }
            }
        }
        $obj->save();
        return $this->oneToJson($obj);
    }

    /**
     *  Not much to do for now, but overriding it may be useful,
     *  for example for password encoding or to deal with missing or 
     *  duplicated keys
     */
    protected function processInput($vars, $obj) {
        return $vars;
    }
    
    /**
     * processSingleQuery and processListQuery get a query in the form of an array
     * and just return it. Their purpose is to be overriden by children which may
     * want to add a few conditions there.
     *
     * @query array
     * @return array
     */
    protected function processSingleQuery($query)
    {
        return $query;
    }
    
    protected function processListQuery($query)
    {
        return $query;
    }
    protected function getModel() {
        $class= "\Model\\".$this->model;
        return new $class;
    }

    protected function oneToJson($object) {
        $this->plural = $this->findPlural($this->model);
        $arr = [
            "links" => [
                "self" => "/api/".$this->plural."/".$object->id
            ],
            "data" => $this->oneToArray($object)
        ];
        return json_encode($arr);
    }

    protected function manyToJson($list) {
        $this->plural = $this->findPlural($this->model);
        $arr = [
            "links" => [
                "self" => "/api/".$this->plural
            ],
            "data" => []
        ];
        foreach($list?:[] as $item) {
            $arr["data"][] = $this->oneToArray($item);
        }
        return json_encode($arr);
    }

    protected function oneToArray($object) {
        $arr = $object->cast(); 
        $fields = $object->getFieldConfiguration();
        $link = "/api/".$this->plural."/".$object->id;
        $ret = [
            "type" => $this->plural,
            "id" => $object->id,
            "attributes" => [],
            "relationships" => []
        ];
        foreach($arr as $key=>$value) {
            if(in_array($key, $this->blacklist)) continue;

            $relType = $fields[$key]['relType'];
            if(!empty($fields[$key][$relType])) {
                $class =explode("/", $fields[$key][$relType][0]);
                $type = $this->findPlural(end($class));
            } else { $type = $key; }

            switch($relType) {
            case 'has-many':

                $ret["relationships"][$key] = [
                    "links" => [
                        "self" => $link."/relationships/".$key,
                        "related" => $link."/".$key
                    ],
                    "data" => []
                ];
                foreach($value?:[] as $entry) {
                    $ret["relationships"][$key]["data"][] = [
                        "type" => $type,
                        "id" => $entry['_id']
                    ];
                }
                break;
            case 'belongs-to': 
                $ret["relationships"][$key] = [
                    "links" => [
                        "self" => $link."/relationships/".$key,
                        "related" => $link."/".$key
                    ],
                    "data" => [
                        "type" => $type,
                        "id" => $value->id
                    ]
                ];
                break;
            default: 
                $ret["attributes"][$key] = $value;
                break;
            }
        }

        return $ret;
    }

    protected function findPlural($name) {
        $f3 = \Base::instance();
        if(!empty($name) && $f3->models) {
            echo $name;
            $keys = array_flip($f3->models);
            print_r($keys);
            if(isset($keys[$name])) return $keys[$name];
        }
        return $name."s";
    }
}
