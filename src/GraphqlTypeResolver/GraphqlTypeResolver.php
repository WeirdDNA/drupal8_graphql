<?php
/**
 * @file
 * Contains \Drupal\graphql_api\Controller\GraphqlTypeResolver.
 */

namespace Drupal\graphql_api\GraphqlTypeResolver;
class GraphqlTypeResolver{
  protected $args, $mutations, $customFields;
  public function getOperations(){
    return array(
      "args"=>$this->args,
      "mutations"=>$this->mutations,
	  "customFields"=>$this->customFields,
    );
  }
}
?>
