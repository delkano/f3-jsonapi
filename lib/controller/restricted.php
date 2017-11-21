<?php
namespace Controller;

/**
 * Restricted is a common variant of a standard jsonApi controller,
 * where most users can only see and edit their own created objects,
 * unless they have some particular roles.
 */

class Restricted extends JsonApi {
    public function __construct($model, $blacklist=[], $accepted_roles=['ADMIN'], $owner_field='user', $user_var='user', $role_var='role') {
        parent::__construct($model, $blacklist);

        $this->accepted_roles = $accepted_roles;
        $this->owner_field = $owner_field;
        $this->user_var = $user_var;
        $this->role_var = $role_var;
    }
    protected function processSingleQuery($query)
    {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->user;
            if(in_array($user->get("role_var"), $accepted_roles)) {
                if(!empty($query)) $query[0].= " AND";
                $query[0].= "`$this->owner_field`=?";
                $query[] = $user->id;
            }
            return $query;
        } else throw new Exception("No user loaded."); 
    }
    
    protected function processListQuery($query)
    {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
            if(in_array($user->get("role_var"), $accepted_roles)) {
                if(!empty($query)) $query[0].= " AND";
                $query[0].= "`".$this->owner_field."_id`=?";
                $query[] = $user->id;
            }
            return $query;
        } else throw new Exception("No user loaded."); 
    }

    protected function processInput($vars, $obj) {
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
        
            if($obj->dry()) //New object 
                $obj->set("owner_field", $user);
            else if($obj->get("owner_field") !== $user && !in_array($user->get("role_var"), $this->accepted_roles))
                throw new Exception("User has no permissions to edit this"); //Replace with an actual HTTP error 

            return $vars;
        } else throw new Exception("No user loaded."); 
    }
}
