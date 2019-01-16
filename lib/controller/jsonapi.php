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
    public function __construct($model, $blacklist = []) {
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
        // TODO We don't support includes, for the time being
        if(isset($f3["GET.include"]))
            $f3->error(400, "Include is not yet implemented");
        // Here we evaluate a few common filters, if any exist.
        if(isset($f3["GET.filter"])) {
            $filters = $f3["GET.filter"];
            $fields = array_keys($model->getFieldConfiguration());
            // We want: field equals, field from, field to, field not equals.
            $endings = [
                "not" => "!=",
                "from" => ">=",
                "to" => "<=",
                "over" => ">",
                "under" => "<"
            ];
            foreach($filters as $filter=>$value) {
                if(in_array($filter, $fields)) { // equals
                    $vals = explode(',', $value);
                    $filter_query = implode(" OR ", array_fill(0, count($vals), "`$filter` = ?"));
                    if(!empty($query[0]))
                        $query[0].= " AND ";
                    $query[0].= "($filter_query)";
                    $query = array_merge($query, $vals);
                } else foreach($endings as $end => $sign) {
                    $l = -1 - strlen($end);
                    if(substr($filter, $l) == "_$end" && in_array(substr($filter, 0, $l), $fields)) {
                        if(!empty($query[0]))
                            $query[0].= " AND ";
                        $query[0].= "(`".substr($filter, 0, $l)."` $sign ?)";
                        $query[] = $value;
                    }
                }
            }
        }
        // TODO Here we should add sorting, if requested
        if(isset($f3["GET.sort"])) {
            $f3->error(400, "Sorting is not yet implemented");
        }

        //$f3->log->write(var_export($query, true));
        // Here we paginate, if requested
        if(isset($f3["GET.page"])) {
            $pos = intval($f3["GET.page.number"])?:0;
            $limit = intval($f3["GET.page.size"])?:10; // Default should actually be in config.ini
            $list = $model->paginate($pos, $limit, $query);
        } else 
            $list = $model->find($query);

        $r = $this->manyToJson($list);
        $f3->log->write($r);
        echo $r;
    }

    public function create($f3) {
        $model = $this->getModel();
        echo $this->save($f3, $model);
    }

    public function update($f3, $params) {
        $id = intval($params['id']);
        $model = $this->getModel();

        $query = ["id=?", $id];
        $query = $this->processSingleQuery($query); // Allows customization in children
        $model->load($query);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }
        echo $this->save($f3, $model);
    }

    public function delete($f3, $params) {
        $id = intval($params['id']);
        $model = $this->getModel();

        $query = ["id=?", $id];
        $query = $this->processSingleQuery($query); // Allows customization in children
        $model->load($query);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }

        $model->erase();

        $arr = [
            "meta" => []
        ];
        echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    }

    public function relationships($f3, $params) {
        $id = intval($params['id']);
        $relationship = $params['relationship'];

        $model = $this->getModel();
        $model->load(["id=?", $id]);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }

        if(!$this->plural)
            $this->plural = $this->findPlural($this->model);

        $fields = $model->getFieldConfiguration();
        $relType = $fields[$relationship]['relType'];
        if(!empty($fields[$relationship][$relType])) {
            if($relType == 'belongs-to-one')
                $class =explode('\\', $fields[$relationship][$relType]);
            else
                $class =explode('\\', $fields[$relationship][$relType][0]);

            $type = $this->findPlural(end($class));
        } else { $type = $relationship; }


        $arr = [
            "links" => [
                "self" => "/api/".$this->plural."/$id/relationships/".$relationship,
                "related" => "/api/".$this->plural."/$id/".$relationship
            ],
            "data" => []
        ];

        if($relType == 'belongs-to-one') {
            $obj = $model->get($relationship);

            $arr['data'] = [
                "type" => $type,
                "id" => $obj['_id']
            ];

        } else { // Assuming 'has-many'
            $list = $model->get($relationship);

            $this->orderRelationship($relationship, $list);

            foreach($list?:[] as $entry) {
                $arr["data"][] = [
                    "type" => $type,
                    "id" => $entry['_id']
                ];
            }
        }
        echo json_encode($arr);
    }

    public function related($f3, $params) {
        $id = intval($params['id']);
        $model = $this->getModel();
        $model->load(["id=?", $id]);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }
        $related = $params['related'];

        $fields = $model->getFieldConfiguration();
        $relType = $fields[$related]['relType'];

        $list = $model->get($related);


        if($relType == 'belongs-to-one') {
            $controller = "\\Controller\\".end(explode("\\", $fields[$related][$relType]));
            echo (new $controller)->oneToJson($list);
        } else { // has-many. If it's a different thing maybe I'll find a problem later
            $controller = "\\Controller\\".end(explode("\\", $fields[$related][$relType][0]));
            $this->orderRelationship($related, $list);
            echo (new $controller)->manyToJson($list);
        }
    }

    /* Helper methods */

    protected function save($f3, $obj) {
        $vars = json_decode($f3->BODY, true); // 'true' makes it an array (to avoid issues with dashed names)
        if(!isset($vars["data"])) {
            $f3->error(400, "Malformed payload");
        }
        $vars = $vars["data"];
        $vars = $this->processInput($vars, $obj); 
        $valid_fields = array_keys($obj->cast(null, 0));
        $conf = $obj->getFieldConfiguration();
        $attributes = $vars["attributes"];
        // Update all standard fields
        foreach($valid_fields?:[] as $key) {
            if(
                isset($attributes[$key]) // Since this includes the valid values "0" and false, ...
                || ($attributes[$key] === null && $conf[$key]["nullable"])
            ) {
                $obj->$key = $attributes[$key];
            }
        }
        // Update relationships
        foreach($vars["relationships"]?:[] as $rel => $data) {
            if(in_array($rel, $valid_fields)) {
                if(!isset($data['data'])) $f3->error(400, "Malformed payload");
                $data = $data['data'];
                if(is_array($data) && !$this->is_assoc($data)) {
                    $rels = [];
                    foreach($data?:[] as $entry) {
                        $rels[] = intval($entry['id']);
                    }
                    $obj->$rel = $rels;
                } else {
                    $f3->log->write(var_export($data, true));
                    $obj->$rel = $data['id']?intval($data['id']):null;
                }
            }
        }
        $obj->save();
        $this->postSave($obj); // Post-Save hook
        return $this->oneToJson($obj);
    }

    private function is_assoc(array $array) {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }

    /**
     *  Not much to do for now, but overriding it may be useful,
     *  for example for password encoding or to deal with missing or 
     *  duplicated keys
     */
    protected function processInput($vars, $obj) {
        return $vars;
    }
    protected function postSave($obj) {}
    
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
    /**
     * orderRelationship exists to be overriden by children which need some 
     * ordered relationship.
     */
    protected function orderRelationship($relationname, $collection)
    {
        return null;
    }

    protected function getModel() {
        $class= "\Model\\".$this->model;
        return new $class;
    }

    protected function oneToJson($object) {
        if(!$this->plural)
            $this->plural = $this->findPlural($this->model);
        $arr = [
            "links" => [
                "self" => "/api/".$this->plural."/".$object->id
            ],
            "data" => ($object&&!$object->dry())?$this->oneToArray($object):null
        ];
        return json_encode($arr, JSON_UNESCAPED_SLASHES);
    }

    protected function manyToJson($list) {
        if(!$this->plural)
            $this->plural = $this->findPlural($this->model);

        $arr = [
            "links" => [
                "self" => "/api/".$this->plural
            ],
            "data" => []
        ];
        if(isset($list["subset"])) { // We have pagination here
            $link = "/api/".$this->plural."?page[size]=".$list["limit"]."&page[number]=";
            $arr["links"]["first"] = $link."0";
            $arr["links"]["last"] = $link.($list["count"]-1);
            $current = $list["pos"];
            $arr["links"]["prev"] = ($current>0)? $link.($current-1) : null;
            $arr["links"]["next"] = ($current<($list["count"]-1))? $link.($current+1) : null;

            $list = $list["subset"];
        }

        $this->number = 0;
        foreach($list?:[] as $item) {
            $arr["data"][] = $this->oneToArray($item);
        }
        return json_encode($arr, JSON_UNESCAPED_SLASHES);
    }

    protected function oneToArray($object) {
        $arr = $object->cast(null,0); 
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

            switch($relType) {
            case 'has-many':
                // We've added "async" to the Cortex Model definition.
                if(!empty($fields[$key]["async"])) break;
                if(!empty($fields[$key][$relType])) {
                    $class =explode("\\", $fields[$key][$relType][0]);
                    $type = $this->findPlural(end($class));
                } else { $type = $key; }

                $ret["relationships"][$key] = [
                    "links" => [
                        "self" => $link."/relationships/".$key,
                        "related" => $link."/".$key
                    ],
                    "data" => []
                ];

                // This reduces the risk of memory exhaustion on large sets
                $i = 0; $l=10;
                $object->countRel($key);
                do {
                    $object->filter($key,null,['limit'=>$l,'offset'=>$i*$l]);
                    $object->load(["id=?", $object->id]);
                    $count = $object["count_$key"] ."- ";
                    foreach($object->$key?:[] as $entry) {
                        $ret["relationships"][$key]["data"][] = [
                            "type" => $type,
                            "id" => $entry['_id']
                        ];
                    }
                    $i++;
                } while($i*$l < $count && $object->$key);
                break;
            case 'belongs-to-one': 
                if(!empty($fields[$key][$relType])) {
                    $class =explode("\\", $fields[$key][$relType]);
                    $type = $this->findPlural(end($class));
                } else { $type = $key; }

                $ret["relationships"][$key] = [
                    "links" => [
                        "self" => $link."/relationships/".$key,
                        "related" => $link."/".$key
                    ],
                    "data" => [
                        "type" => $type,
                        "id" => $value
                    ]
                ];
                break;
            default: 
                $key = str_replace("_", "-", $key);
                $ret["attributes"][$key] = $value;
                break;
            }
        }

        return $ret;
    }

    protected function findPlural($name) {
        $f3 = \Base::instance();
        if(!empty($name) && $f3->models) {
            $keys = array_flip($f3->models);
            if(isset($keys[$name])) return $keys[$name];
        }
        return $name."s";
    }
}
