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

        if(isset($f3["GET.include"])) {
            $includes = explode(",", $f3["GET.include"]);

            $includes = array_map(function($include) {
                $divs = explode(".", $include);
                if(count($divs)>1) 
                    return $divs;
                else
                    return $include;
                
            }, $includes);

        } else $includes = null;

        $model = $this->getModel();
        $query = ["id=?", $id];
        $query = $this->processSingleQuery($query, $model); // Allows customization in children
        $model->load($query);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }

        echo $this->oneToJson($model, $includes);
    }

    public function getList($f3) {
        $model = $this->getModel();
        // Query creation
        $query = [""];

        if(isset($f3["GET.include"])) {
            $includes = explode(",", $f3["GET.include"]);

            $includes = array_map(function($include) {
                $divs = explode(".", $include);
                if(count($divs)>1) 
                    return $divs;
                else
                    return $include;
                
            }, $includes);

        } else $includes = null;

        $query = $this->filterQuery($f3, $query);

        $query = $this->processListQuery($query, $model); // Allows customization in children
        $options = [];
        // Here we add sorting, if requested
        if(!empty($f3["GET.sort"])) {
            $terms = explode(",", $f3["GET.sort"]);
            $terms = array_map(function($term) {
                $model = $this->getModel();
                switch($term[0]) {
                case "+": $word = substr($term, 1); $ord = " ASC"; break;
                case "-": $word = substr($term, 1); $ord = " DESC";  break;
                default: $word = $term; $ord = " ASC"; break;
                }
                if($model->exists($word))
                    return $word.$ord;
                else return false;
            }, $terms);
            //$terms = array_filter($terms, function($s){return $s;});
	    $options["order"] = implode(",", $terms);
        } else
		$options["order"] = $this->defaultOrder?:null;
	$options["group"] = $this->groupBy?:null;

        // Here we paginate, if requested
        if(isset($f3["GET.page"])) {
            $pos = intval($f3["GET.page.number"])?:0;
            $limit = intval($f3["GET.page.size"])?:10; // Default should actually be in config.ini
            $list = $model->paginate($pos, $limit, $query, $options);
        } else 
            $list = $model->find($query, $options);

        //$f3->log->write($f3->DB->log());
        $r = $this->manyToJson($list, $includes);
        echo $r;
    }

    protected function filterPart($f3, $query, $filters, $conf, $model, $fields, $endings, $conj = " AND ") {
        foreach($filters as $filter=>$value) {
            if($filter=="or") {
                foreach($value as $group => $subfilters) {
                    $subquery = $this->filterPart($f3, [""], $subfilters, $conf, $model, $fields, $endings, " OR ");
                    if(!empty($query[0]))
                        $query[0].= " AND ";
                    $query[0].= "(".array_shift($subquery).")";
                    $query = array_merge($query, $subquery);
                }
            } else { // normal, AND filters
                $dotted = explode('.', $filter);
                // If filter is has-many relationship:
                if($conf[$filter]['relType']=="has-many" || count($dotted)>1) {
                    if(count($dotted>1)) {
                        $filter = $dotted[0];
                        $filter_key = $dotted[1];
                    } else {
                        $filter_key = "_id";
                    }
                    $arr = explode(",", $value);
                    if(is_array($arr)) {
                        $model->has($filter, ["$filter_key IN ?", $arr]);
                    } else
                        $model->has($filter, ["$filter_key = ?", $value]);
                } else if(in_array($filter, $fields)) { // equals
                    $nullify = function($a) { return $a=="NULL"?NULL:$a; };
                    $vals = array_map($nullify, explode(',', $value));
                    $filter_query = implode(" OR ", array_fill(0, count($vals), "$filter = ?"));
                    if(!empty($query[0]))
                        $query[0].= $conj;
                    $query[0].= "($filter_query)";
                    $query = array_merge($query, $vals);
                } else foreach($endings as $end => $sign) {
                    $l = -1 - strlen($end);
                    if(substr($filter, $l) == "_$end" && in_array(substr($filter, 0, $l), $fields)) {
                        if(!empty($query[0]))
                            $query[0].= $conj;
                        $query[0].= "(".substr($filter, 0, $l)." $sign ?)";
                        $query[] = is_numeric($value)?floatval($value):$value;
                    }
                }

            }
        }
        return $query; 
    }

    public function filterQuery($f3, $query) {
        // Here we evaluate a few common filters, if any exist.
        if(isset($f3["GET.filter"])) {
            $filters = $f3["GET.filter"];
            $model = $this->getModel();
            $conf = $model->getFieldConfiguration();
            $fields = array_keys($conf);
            $fields[] = "id"; // Some people want to filter via "id"
            // We want: field equals, field from, field to, field not equals.
            $endings = [
                "not" => "!=",
                "from" => ">=",
                "to" => "<=",
                "over" => ">",
                "under" => "<"
            ];
        }
        return $this->filterPart($f3, $query, $filters, $conf, $model, $fields, $endings, " AND ");
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
            if(is_array($fields[$relationship][$relType]))
                $class =explode('\\', $fields[$relationship][$relType][0]);
            else
                $class =explode('\\', $fields[$relationship][$relType]);

            $type = $this->findPlural(end($class));
        } else { $type = $relationship; }


        $arr = [
            "links" => [
                "self" => "/api/".$this->plural."/$id/relationships/".$relationship,
                "related" => "/api/".$this->plural."/$id/".$relationship
            ],
            "data" => []
        ];

        if($relType == 'belongs-to-one' || $relType == 'has-one') {
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

    public function deleteRelationships($f3, $params) {
        $id = intval($params['id']);
        $relationship = $params['relationship'];
        $model = $this->getModel();
        $fields = $model->getFieldConfiguration();
        if(!isset($fields[$relationship])) {
            $f3->error(404, "Relationship does not exist");
        }
        $relType = $fields[$relationship]['relType'];

        $model->load(["id=?", $id]);

        $model->$relationship = null;
        $model->save();
        $f3->status(204);
    }

    public function addRelationships($f3, $params) {
        $id = intval($params['id']);
        $relationship = $params['relationship'];
        $model = $this->getModel();
        $fields = $model->getFieldConfiguration();
        if(!isset($fields[$relationship])) {
            $f3->error(404, "Relationship does not exist");
        }
        $relType = $fields[$relationship]['relType'];

        if($relType != "has-many") {
            $f3->error(400, "Bad request");
        }
        $model->load(["id=?", $id]);

        $vars = json_decode($f3->BODY, true); // 'true' makes it an array (to avoid issues with dashed names)
        if(!isset($vars["data"])) {
            $f3->error(400, "Malformed payload");
        }
        $vars = $vars["data"];
        $vars = $this->processInput($vars, $model); 
        
        if(!$model->$relationship) $model->$relationship = [];
        foreach($vars as $rel) {
            $rel_id = intval($rel["id"]);
            // Insert check if id already there. If it is, do not insert. If all are, change return code to 204
            $model->$relationship[] = intval($rel["id"]);
        }
        $model->save();

        $this->relationships($f3, $params);
    }
    public function replaceRelationships($f3, $params) {
        $id = intval($params['id']);
        $relationship = $params['relationship'];
        $model = $this->getModel();
        $fields = $model->getFieldConfiguration();
        if(!isset($fields[$relationship])) {
            $f3->error(404, "Relationship does not exist");
        }
        $relType = $fields[$relationship]['relType'];

        $model->load(["id=?", $id]);

        $vars = json_decode($f3->BODY, true); // 'true' makes it an array (to avoid issues with dashed names)
        if(!isset($vars["data"])) {
            $f3->error(400, "Malformed payload");
        }
        $vars = $vars["data"];
        $vars = $this->processInput($vars, $model); 
        
        if($relType == "has-many") {
            $model->$relationship = [];
            foreach($vars as $rel) {
                $rel_id = intval($rel["id"]);
                $model->$relationship[] = $rel_id;
            }
        } else {
            $rel_id = $vars?intval($vars["id"]):null;
            $model->relationship = $rel_id;
        }
        $model->save();

        $this->relationships($f3, $params);
    }

    public function related($f3, $params) {
        $id = intval($params['id']);
        $related = $params['related'];
        $model = $this->getModel();

        $fields = $model->getFieldConfiguration();
        if(!isset($fields[$related])) {
            $f3->error(404, "Relationship does not exist");
        }
        $relType = $fields[$related]['relType'];

        if($relType == "has-many") {
            $controllerName = "\\Controller\\".end(explode("\\", $fields[$related][$relType][0]));
            // It should possibly be filtered. This requires first: getModel; then filterQuery; then manyToJson
            $controller = new $controllerName;
            $query = $controller->filterQuery($f3, []);
            $model->filter($related, $query);
        }
        // We delay the actual loading of the model as to be able to use the Cortex "filter" method
        $model->load(["id=?", $id]);

        if($model->dry()) {
            $f3->error(404, $this->model." id: ".$id." was not found in the database");
        }


        if($relType == 'belongs-to-one' || $relType == 'has-one') {
            $name = $fields[$related][$relType];
            if(is_array($name)) $name = $name[0];
            $controller = "\\Controller\\".end(explode("\\", $name));
            $list = $model->get($related);
            echo (new $controller)->oneToJson($list);
        } else { // has-many. If it's a different thing maybe I'll find a problem later
            $list = $model->get($related);
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
            $ext_key = str_replace("_", "-", $key);
            if(
                isset($attributes[$ext_key]) // Since this includes the valid values "0" and false, ...
                || ($attributes[$ext_key] === null && $conf[$key]["nullable"])
            ) {
                $obj->$key = $attributes[$ext_key];
            }
        }
        // Update relationships
        foreach($vars["relationships"]?:[] as $rel => $data) {
            if(in_array($rel, $valid_fields)) {
                if(!isset($data['data'])) $f3->error(400, "Malformed payload");
                $data = $data['data'];
                switch($conf[$rel]['relType']) {
                    case "belongs-to-one": case "has-one": 
                        if(!is_array($data) || $this->is_assoc($data)) {
                            $obj->$rel = $data['id']?intval($data['id']):null;
                        } else
                            $f3->error(400, "Malformed payload");
                        break;
                    case "has-many":
                        if(is_array($data) && !$this->is_assoc($data)) {
                            $rels = [];
                            foreach($data?:[] as $entry) {
                                if(!empty($entry['id']))
                                    $rels[] = intval($entry['id']);
                            }
                            $obj->$rel = empty($rels)?null:$rels;
                        } else 
                            $f3->error(400, "Malformed payload");
                        break;
                    default: 
                        break;
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
    protected function processSingleQuery($query, $model=null)
    {
        return $query;
    }
    
    protected function processListQuery($query, $model=null)
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
        if($this->actualModel) return $this->actualModel;
        else {
            $class= "\Model\\".$this->model;
            $this->actualModel = new $class;
            return $this->actualModel;
        }
    }

    protected function oneToJson($object, $includes=null) {
        if(!$this->plural)
            $this->plural = $this->findPlural($this->model);

        $arr = ($object&&!$object->dry())?$this->oneToArray($object, $includes):[];
        if(!isset($arr["data"]))
            $arr = [ "data" => $arr ];
        $arr["links"] = [
            "self" => "/api/".$this->plural."/".$object->id
        ];
        return json_encode($arr, JSON_UNESCAPED_SLASHES);
    }

    protected function manyToJson($list, $includes=false) {
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
            $one = $this->oneToArray($item, $includes);
            if(!empty($one["included"])) {
                $arr["data"][] = $one["data"];

                if(!isset($arr["included"])) $arr["included"] = [];

                if(is_array($one["included"])) {
                    $arr["included"] = array_merge($arr["included"], $one["included"]);
                } else 
                    $arr["included"][] = $one["included"];

            } else {
                $arr["data"][] = $one;
            }
        }
        if(!empty($arr["included"])) {
            // Let's remove duplicates
            $included = [];
            foreach($arr["included"] as $inc) {
                $all_found = array_merge($included, $arr["data"]);
                if(!$this->findInIncluded($inc, $all_found)) 
                    $included[] = $inc;
            }
            $arr["included"] = $included;
        }
        return json_encode($arr, JSON_UNESCAPED_SLASHES);
    }

    protected function findInIncluded($elt, $list) {
        foreach($list as $item) {
            if($item["type"] === $elt["type"]
                && $item["id"] === $elt["id"])
                return true;
        }
        return false;
    }

    /**
     * This method can be used for relationship control.
     * array is passed as reference to reduce overhead
     */
    protected function processObjectArray($object, &$array) {
        return;
    }

    protected function oneToArray($object, $includes=false) {
        $arr = $object->cast(null,0); 
        $fields = $object->getFieldConfiguration();
        if(!$this->plural)
            $this->plural = $this->findPlural($this->model);
        $link = "/api/".$this->plural."/".$object->id;
        $ret = [
            "type" => $this->plural,
            "id" => $object->id,
            "attributes" => [],
            "relationships" => []
        ];
        $included = [];
        $local_includes = [];
        $exported_includes = [];
        foreach($includes?:[] as $include) {
            if(is_array($include)) {
                $import_name = array_shift($include);
                $local_includes[] = $import_name;
                $exported_includes[$import_name] = $include;
            } else {
                $local_includes[] = $include;
            }
        }
        $this->processObjectArray($object, $arr); // Hook for children
        foreach($arr as $key=>$value) {
            if(in_array($key, $this->blacklist)) continue;

            $relType = $fields[$key]['relType'];

            switch($relType) {
            case 'has-many':
                if( ($includes && !in_array($key, $local_includes))
                   || (!$includes && !empty($fields[$key]["async"]))) {
                    // We've added "async" to the Cortex Model definition.
                    // If there are no "includes" and it's async, or there are "includes" and it's not in it, skip
                    $ret["relationships"][$key] = [
                        "links" => [
                            "self" => $link."/relationships/".$key,
                            "related" => $link."/".$key
                        ]
                    ];
                    break;   
                }
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

                foreach($object->$key?:[] as $entry) {
                    $ret["relationships"][$key]["data"][] = [
                        "type" => $type,
                        "id" => is_int($entry)?$entry:$entry['_id']
                    ];
                    if($includes) {
                        $controller = "\\Controller\\".($class?end($class):$key);
                        $including = isset($exported_includes[$key])?[implode(".", $exported_includes[$key])]:false;
                        $include_array = (new $controller)->oneToArray($entry, $including);
                        if($including) {
                            $included[] = $include_array['data'];
                            if($include_array['included']) {
                                $included = array_merge($included, $include_array['included']);
                            }
                        } else {
                            $included[] = $include_array;
                        }
                    }
                }
                break;
            case 'belongs-to-one': case 'has-one': 
                if( ($includes && !in_array($key, $local_includes))
                   || (!$includes && !empty($fields[$key]["async"]))) {
                    // We've added "async" to the Cortex Model definition.
                    // If there are no "includes" and it's async, or there are "includes" and it's not in it, skip
                    $ret["relationships"][$key] = [
                        "links" => [
                            "self" => $link."/relationships/".$key,
                            "related" => $link."/".$key
                        ]
                    ];
                    break;   
                }
                $classname = $fields[$key][$relType];
                if(is_array($classname)) $classname = $classname[0];
                if(!empty($classname)) {
                    $class =explode("\\", $classname);
                    $type = $this->findPlural(end($class));
                } else { $type = $key; }

                $ret["relationships"][$key] = [
                    "links" => [
                        "self" => $link."/relationships/".$key,
                        "related" => $link."/".$key
                    ],
                    "data" => []
                ];
                
                if($object->$key) {
                    $ret["relationships"][$key]["data"] = [
                        "type" => $type,
                        "id" => $value?:$object->$key->id
                    ];
                    if($includes) {
                        $controller = "\\Controller\\".($class?end($class):$key);
                        if($object->$key) {
                            $including = isset($exported_includes[$key])?[implode(".", $exported_includes[$key])]:false;
                            $include_array = (new $controller)->oneToArray($object->$key, $including);
                            if($including) {
                                $included[] = $include_array['data'];
                                if(!empty($include_array['included'])) {
                                    $included = array_merge($included, $include_array['included']);
                                }
                            } else {
                                $included[] = $include_array;
                            }
                        }
                    }
                } else {
                    $ret["relationships"][$key]["data"] = null;
                }

                break;
            default: 
                $key = str_replace("_", "-", $key);
                $ret["attributes"][$key] = $value;
                break;
            }
        }

        if(!empty($included)) {
            $ret = [
                "data" => $ret,
                "included" => array_values(array_filter(array_unique($included, SORT_REGULAR), function($val) {return !is_null($val); } ))
            ];
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
