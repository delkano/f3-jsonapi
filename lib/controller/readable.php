<?php
namespace Controller;

/**
 * Readable is a common variant of a standard jsonApi controller,
 * where most users can only edit their own created objects,
 * unless they have some particular roles.
 */

class Readable extends JsonApi {
    public function __construct($model, $blacklist=[], $accepted_roles=['ADMIN'], $owner_field='creator', $user_var='user', $role_var='role') {
        parent::__construct($model, $blacklist);

        $this->accepted_roles = $accepted_roles;
        $this->owner_field = $owner_field;
        $this->user_var = $user_var;
        $this->role_var = $role_var;
    }

    protected function processInput($vars, $obj) {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
        
            if($obj->dry()) { //New object 
                if(!in_array($user->get($this->role_var), $this->accepted_roles) || empty($vars["attributes"][$this->owner_field]))
                    $vars["relationships"][$this->owner_field]["data"]["id"] = $user->id; // We're not admin or trying to set the owner
            } else if($obj->get($this->owner_field)->id != $user->id && !in_array($user->get($this->role_var), $this->accepted_roles))
                $f3->error(403, "You have not the permissions required to do this.");

            return $vars;
        } else $f3->error(403, "You are not authenticated"); 
    }
}
