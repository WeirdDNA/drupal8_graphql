<?php
/**
 * @file
 * Contains \Drupal\graphql_api\Controller\GraphqlController.
 */

namespace Drupal\graphql_api\Controller;
use Drupal\graphql_api\Parser\Parser;
use GraphQL\GraphQL;
use GraphQL\Schema;
use GraphQL\Type\Introspection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class GraphqlController extends \Drupal\Core\Controller\ControllerBase {
  public function shorthand(){
    $type_doc = Parser::getShortHand();
    return array(
      '#type' => 'markup',
      "#markup"=>"<pre>$type_doc</pre>"
    );
  }
  public function schema(){
    $schema = GraphqlController::getSchema();
    $query =  Introspection::getIntrospectionQuery();
    $output = GraphQL::execute($schema, $query, $root, $variableValues);
    return new Response(json_encode($output), 200, array('Content-Type' => 'application/json'));
  }

  public function content(Request $request) {
    $schema = GraphqlController::getSchema();
    $query = "";
    $request_body = file_get_contents('php://input');
    $request_body = str_replace("\r", "", $request_body);
    $request_body = str_replace("\n", " ", $request_body);
    $request_json = json_decode($request_body);
    $variableValues = null;
    $root = null;
    if (isset($request_json->query)){
    	$query = $request_json->query;
    	if (isset($request_json->variables)){
    		$variables = $request_json->variables;
    		$variableValues = array();
    		foreach($variables as $key=>$value){
    			$variable = array();
    			foreach($value as $fieldName => $fieldValue){
    				$variable[$fieldName] = $fieldValue;
    			}
    			$variableValues[$key] = $variable;
    		}
    	}
    }
    elseif (isset($_POST["query"])){
    	$query = $_POST["query"];
      if (isset($_POST["variables"])){
        $variables = \json_decode($_POST["variables"]);
        $variableValues = array();
        foreach($variables as $key=>$value){
          $variable = array();
          foreach($value as $fieldName => $fieldValue){
            $variable[$fieldName] = $fieldValue;
          }
          $variableValues[$key] = $variable;
        }
      }
    }
    if ($query == ""){
      $output = new \stdClass();
    }
    else{
      $output = GraphQL::execute($schema, $query, $root, $variableValues);
    }
    return new Response(json_encode($output), 200, array('Content-Type' => $request->getMimeType('json')));
  }
  private static function setFieldResolve($field,$base_class){
    $is_edge = false;
    $base_class_name = $base_class->name;
    if (substr($base_class_name,strlen($base_class_name)-4,4) == "Edge"){
      $is_edge = true;
    }

    if ($base_class_name == "PageInfo"){
      $is_edge = true;
    }
    if ((get_class($base_class) == "GraphQL\Type\Definition\ObjectType")&&(!$is_edge)){

      if ($type->name == "Mutation"){
        $field->resolveFn = function($root, $args, $resolveInfo){

          $resolver_dictionary = Parser::getTypeDictionary("resolver_class");
          $mutations = array();
          foreach($resolver_dictionary as $type_name=>$class_name){
            if ($class_name != ""){
              $resolver = new $class_name();
              $ops = $resolver->getOperations();
              foreach($ops["mutations"] as $mutation_name=>$mutation){
                $mutations[$mutation_name] = array("resolver"=>$resolver,"mutation"=>$mutation);
              }
            }
          }
          $resolver = $mutations[$resolveInfo->fieldName]["resolver"];

          $method_name =  $mutations[$resolveInfo->fieldName]["mutation"]["method"];
          return $resolver->$method_name($root,$args,$resolveInfo);
        };

      }
      else{
        $field->resolveFn = function($root,$args,$resolveInfo){
          $result = GraphqlController::resolveAny($resolveInfo->returnType, $root, $args, $resolveInfo);
          if (isset($result["_list"])){
            return $result["_list"];
          }
          else{
            if (count($result) == 1){
              return $result[0];
            }
            else{
              return $result;
            }
          }
          return $result;
        };
      }

    }
    return $field;
  }
  private static function getSchema(){
    $type_doc = Parser::getShortHand();
    $types = Parser::parseSchemaShorthand($type_doc);
    $missed_fields = array();
    foreach($types as $type){
      $fields = $type->getFields();
      foreach($fields as $name=>$field){
        $base_class = $field->config["type"];
        while ((get_class($base_class) == "GraphQL\Type\Definition\NonNull") || (get_class($base_class) == "GraphQL\Type\Definition\ListOfType")){
          $base_class = $field->config["type"]->getWrappedType();
        }

        if (get_class($base_class) == "Closure"){
          $missed_fields[] = array("type"=>$type->name,"field"=>$name);
        }
        else{
          $field = GraphqlController::setFieldResolve($field, $base_class);
        }

      }
    }

    $loops = 0;
    while (count($missed_fields) > 0){
      for($i = 0; $i< count($missed_fields); $i++){
        $missed_field = $missed_fields[$i];
        $missing_field_type_name = $missed_field["type"];
        $missing_field_type_fields = $types[$missing_field_type_name]->getFields();
        foreach($missing_field_type_fields as $field_name=>$field){
          if ($field_name == $missed_field["field"]){
            $base_class = $field->config["type"];
            if (get_class($base_class) == "Closure"){
                $field->config["type"] = $types[$missing_field_type_name];
                $base_class = $field->config["type"];
            }
            while ((get_class($base_class) == "GraphQL\Type\Definition\NonNull") || (get_class($base_class) == "GraphQL\Type\Definition\ListOfType")){
                //TODO need to fix closures of wrapped types
                $base_class = $field->config["type"]->getWrappedType();
            }

            if (get_class($base_class) != "Closure"){
                $field = GraphqlController::setFieldResolve($field, $base_class);
                unset($missed_fields[$i]);
                $missed_fields = array_values($missed_fields);
                break;
            }
            else{
              print_r($base_class);
            }
          }
        }
        //die("end while");
        $loops++;
        if ($loops >10){
          die("loops: $loops");
        }
      }
    }
    return new Schema($types["Query"], $types['Mutation']);
  }
  private static function graphNodes($root,$args,$resolveInfo){
    $field_dictionary = Parser::getFieldDictionary();
    $type_dictionary = Parser::getTypeDictionary("type_name");
    $cardinality = "single";
    $type = "";
    $limit_to_ids = array();
    $limit_to_entity = "";
    if(get_class($resolveInfo->returnType) == "GraphQL\Type\Definition\ObjectType"){
      $resolveTypeName = $resolveInfo->returnType->name;
      foreach($type_dictionary as $key=>$value){
        if ($value == $resolveTypeName){
          $type = $key;
        }
      }
    }
    else{
      die("not handling plurals yet");
    }
    if (isset($root)){

      $parent_type_name = $resolveInfo->parentType->name;
      $entities = \Drupal::entityManager()->getAllBundleInfo();
      $config = \Drupal::config('graphql.config');
      $entity_name = "";
      $entity_type = "";
      $entity_field_name = "";
      $entity_id = $root["id"];
      foreach($entities as $for_entity_name=>$entity_list){
        $entity_config = $config->get($for_entity_name);
        if (isset($entity_config)){
          foreach($entity_config as $config_type_name=>$main_config){
            if ($main_config["type_name"] == $parent_type_name){
              foreach($main_config["fields"] as $drupal_field_name=>$graph_field_name){
                if ($graph_field_name == $resolveInfo->fieldName){
                  $entity_name = $for_entity_name;
                  $entity_type = $config_type_name;
                  $entity_field_name = $drupal_field_name;
                }
              }
            }
          }
        }
      }
      $entity = \Drupal::entityManager()->getStorage($entity_name)->load($entity_id);
      $entity_fields = $entity->getFields();
      $target_field = $entity_fields[$entity_field_name];
      $values = array();
      foreach($target_field as $key=>$value){
        $values[] = $value->getValue()["target_id"];
      }
      $limit_to_ids = $values;
      $limit_to_entity = $entity_name;
      $limit_to_type = $entity_type;
    }

    if ($limit_to_entity == ""){
      $query = \Drupal::entityQuery('node')
      ->condition('type', $type)->condition('status',1);
      $nids = $query->execute();
    }
    else{
      $nids = $limit_to_ids;
    }

    $nodes = entity_load_multiple('node',$nids);
    foreach($nodes as $node){
      $result = array();
      $result["id"] = $node->id();
      foreach($field_dictionary as $drupal_field_name => $graph_field_name){
        $drupal_field_definition = explode(".",$drupal_field_name);
        if ($drupal_field_definition[0] == $type){
          $value = $node->get($drupal_field_definition[1])->value;
          if ($value != null){
            $result[$graph_field_name] = $value;
          }
        }
      }
      $results[] = $result;
    }
    return $result;
  }
  private static function getData($name, $root, $args, $resolveInfo){
    $type_dictionary = Parser::getTypeDictionary("type_name");
    $field_dictionary = Parser::getFieldDictionary();
    $type = "";
    foreach($type_dictionary as $type_name=>$graph_name){
      if ($name == $graph_name){
        $type = $type_name;
      }
    }

    $graph_connection = false;
    if (substr($type,strlen($type)-10,10) == "Connection"){
      $graph_connection = true;
      $type = substr($type,0,strlen($type)-10);
    }
    $results = GraphqlController::graphNodes($root,$args,$resolveInfo);


    if ($graph_connection){
  		//return graph_edges_to_return($result, $args);
  	}
    if ($name == "Viewer"){
      return array();
    }
    return $results;
  }
  private static function resolveAny($type, $root, $args,$resolveInfo){
  	if (get_class($type) == "GraphQL\Type\Definition\ListOfType"){
  		return array("_list"=>GraphqlController::resolveAny($type->getWrappedType(),$root,$args,$resolveInfo));
  	}
  	else{
  		$base_class = $type;
  		while ((get_class($base_class) == "GraphQL\Type\Definition\NonNull") || (get_class($base_class) == "GraphQL\Type\Definition\ListOfType")){
  			$base_class = $base_class->getWrappedType();
  		}
  		$type = $base_class;
  		return GraphqlController::getData($type->name,$root,$args,$resolveInfo);
  	}

  }
  public function graphiql(){
    //TODO: needs to be relative to module path
    $output = <<<EOT
<!DOCTYPE html>
<html>
<head>
  <style>
    html, body {
      height: 100%;
      margin: 0;
      overflow: hidden;
      width: 100%;
    }
  </style>
  <link href="/modules/custom/graphql_api/graphiql/graphiql.css" rel="stylesheet" />
  <script src="/modules/custom/graphql_api/graphiql/fetch.min.js"></script>
  <script src="/modules/custom/graphql_api/graphiql/react.min.js"></script>
  <script src="/modules/custom/graphql_api/graphiql/react-dom.min.js"></script>
  <script src="/modules/custom/graphql_api/graphiql/graphiql.min.js"></script>
</head>
<body>
  <script>
    // Collect the URL parameters
    var parameters = {};
    window.location.search.substr(1).split('&').forEach(function (entry) {
      var eq = entry.indexOf('=');
      if (eq >= 0) {
        parameters[decodeURIComponent(entry.slice(0, eq))] =
          decodeURIComponent(entry.slice(eq + 1));
      }
    });

    // Produce a Location query string from a parameter object.
    function locationQuery(params) {
      return '?' + Object.keys(params).map(function (key) {
        return encodeURIComponent(key) + '=' +
          encodeURIComponent(params[key]);
      }).join('&');
    }

    // Derive a fetch URL from the current URL, sans the GraphQL parameters.
    var graphqlParamNames = {
      query: true,
      variables: true,
      operationName: true
    };

    var otherParams = {};
    for (var k in parameters) {
      if (parameters.hasOwnProperty(k) && graphqlParamNames[k] !== true) {
        otherParams[k] = parameters[k];
      }
    }
    var fetchURL = "/graphql";//locationQuery(otherParams);

    // Defines a GraphQL fetcher using the fetch API.
    function graphQLFetcher(graphQLParams) {
      return fetch(fetchURL, {
        method: 'post',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(graphQLParams),
        credentials: 'include',
      }).then(function (response) {
        return response.text();
      }).then(function (responseBody) {
        try {
          return JSON.parse(responseBody);
        } catch (error) {
          return responseBody;
        }
      });
    }

    // When the query and variables string is edited, update the URL bar so
    // that it can be easily shared.
    function onEditQuery(newQuery) {
      parameters.query = newQuery;
      updateURL();
    }

    function onEditVariables(newVariables) {
      parameters.variables = newVariables;
      updateURL();
    }

    function updateURL() {
      history.replaceState(null, null, locationQuery(parameters));
    }

    // Render <GraphiQL /> into the body.
    React.render(
      React.createElement(GraphiQL, {
        fetcher: graphQLFetcher,
        onEditQuery: onEditQuery,
        onEditVariables: onEditVariables,
        query: undefined,
        response: null,
        variables: null
      }),
      document.body
    );
  </script>
</body>
</html>
EOT;
    return new Response($output, 200, array('Content-Type' => 'text/html'));
  }

}
?>
