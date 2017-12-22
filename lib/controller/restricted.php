<?php
namespace Controller;

/**
 * Restricted is a common variant of a standard jsonApi controller,
 * where most users can only see and edit their own created objects,
 * unless they have some particular roles.
 */

class Restricted extends JsonApi {
    public function __construct($model, $blacklist=[], $accepted_roles=['ADMIN'], $owner_field='owner', $user_var='user', $role_var='role') {
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
            if(in_array($user->get($this->role_var), $this->accepted_roles)) {
                if(!empty($query)) $query[0].= " AND";
                $query[0].= "`$this->owner_field`=?";
                $query[] = $user->id;
            }
            return $query;
        } else $f3->error(403, "You are not authenticated"); 
    }
    
    protected function processListQuery($query)
    {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
            if(in_array($user->get($this->role_var), $this->accepted_roles)) {
                if(!empty($query)) $query[0].= " AND";
                $query[0].= "`".$this->owner_field."_id`=?";
                $query[] = $user->id;
            }
            return $query;
        } else $f3->error(403, "You are not authenticated"); 
    }

    protected function processInput($vars, $obj) {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
        
            if($obj->dry()) //New object 
                $obj->set($this->owner_field, $user);
            else if($obj->get($this->owner_field) !== $user && !in_array($user->get($this->role_var), $this->accepted_roles))
                $f3->error(403, "You have not the permissions required to do this.");

            return $vars;
        } else $f3->error(403, "You are not authenticated"); 
    }
}
