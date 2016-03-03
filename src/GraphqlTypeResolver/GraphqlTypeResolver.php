<?php
/**
 * @file
 * Contains \Drupal\graphql_api\Controller\GraphqlTypeResolver.
 */

namespace Drupal\graphql_api\GraphqlTypeResolver;
class GraphqlTypeResolver{
  protected $args, $mutations;
  public function getOperations(){
    return array(
      "args"=>$this->args,
      "mutations"=>$this->mutations
    );
  }
}
?>
