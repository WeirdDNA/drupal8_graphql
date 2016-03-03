<?php
/**
 * @file
 * Contains \Drupal\graphql_api\Sample\UserResolver. extends GraphqlTypeResolver
 */
namespace Drupal\graphql_api\Sample;

use Drupal\graphql_api\GraphqlTypeResolver\GraphqlTypeResolver;

class UserResolver extends GraphqlTypeResolver{
  public function __construct(){
    $this->args = array(
      "ids"=>"[ID]",
      "name"=>"String"
    );

    $this->mutations = array(
      "login"=>array(
        "args"=>array(
          "userName"=>"String!",
          "password"=>"String!"
        ),
        "return"=>"User",
        "method"=>'loginMutation'
      ),
      "logout"=>array(
        "return"=>"User",
        "method"=>'logoutMutation'
      ),
      "register"=>array(
        "args"=>array(
          "userName"=>"String!",
          "password"=>"String!"
        ),
        "return"=>"User",
        "method"=>"registerMutation"
      ),
      "setEmail"=>array(
        "args"=>array(
          "email"=>"String!"
        ),
        "return"=>"User",
        "method"=>"setEmailMutation"
      ),
    );
  }
  public function mainQuery($root, $args, $resolveInfo){

  }
  public function loginMutation($root,$args,$resolveInfo){
    user_logout();
    $uid= \Drupal::service('user.auth')->authenticate($args["userName"], $args["password"]);
    $account = \Drupal\user\Entity\User::load($uid);
    user_login_finalize($account);
    $account = \Drupal::currentUser();
    $uid = $account->id();
    if ($uid > 0){
        return array("id"=>"me","name"=>$account->getDisplayName());
    }
    return array("id"=>"me","name"=>"Anonymous User");
  }
  public function logoutMutation($root,$args,$resolveInfo){
    user_logout();
    return array("id"=>"me","name"=>"Anonymous User");
  }
  public function registerMutation($root,$args,$resolveInfo){
    return array();
  }
  public function setEmailMutation($root,$args,$resolveInfo){
    return array();
  }
}
?>
