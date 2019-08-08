<?php
namespace Controller;

/**
 * Restricted is a common variant of a standard jsonApi controller,
 * where most users can only see and edit their own created objects,
 * unless they have some particular roles.
 */

class Restricted extends Readable {
    protected function processSingleQuery($query)
    {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->user;
            if(!in_array($user->get($this->role_var), $this->accepted_roles)) {
                if(!empty($query[0])) $query[0].= " AND ";
                $query[0].= "$this->owner_field=?";
                $query[] = $user->id;
            }
            return $query;
        } else $f3->error(403, "You are not authenticated"); 
    }
    
    protected function processListQuery($query, $model=null)
    {
        $f3 = \Base::instance();
        if($f3->exists($this->user_var)) {
            $user = $f3->get($this->user_var);
            if(!in_array($user->get($this->role_var), $this->accepted_roles)) {
                if(!empty($query[0])) $query[0].= " AND ";
                $query[0].= "".$this->owner_field."=?";
                $query[] = $user->id;
            }
            return $query;
        } else $f3->error(403, "You are not authenticated"); 
    }
}
