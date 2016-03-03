<?php
/**
 * @file
 * Contains \Drupal\graphql_api\Parser\Parser.
 */

namespace Drupal\graphql_api\Parser;
use GraphQL\GraphQL;
use GraphQL\Schema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\field\Entity\FieldConfig;


use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Introspection;

global $graph_ql_self_references, $global_types;
$graph_ql_self_references = array();
$global_types = array();


class Parser {
  public static function getTypeDictionary($name_type){
    $entities =  \Drupal::entityManager()->getAllBundleInfo();
    $config = \Drupal::config('graphql.config');
    $type_dictionary = array();
    foreach($entities as $entity_type=>$entity_list){
      $entity_config = $config->get($entity_type);
      if (isset($entity_config)){
        foreach($entity_list as $name=>$unnecessary){
          if (isset($entity_config[$name])){
            $type_dictionary[$name] = $entity_config[$name][$name_type];
          }
        }
      }
    }
    return $type_dictionary;
  }
  public static function getFieldDictionary($name_type){
    $entities =  \Drupal::entityManager()->getAllBundleInfo();
    $config = \Drupal::config('graphql.config');
    $field_dictionary = array();
    foreach($entities as $entity_type=>$entity_list){
      $entity_config = $config->get($entity_type);
      if (isset($entity_config)){
        foreach($entity_list as $name=>$unnecessary){
          if (isset($entity_config[$name])){
            $type = $entity_config[$name];
            foreach($type["fields"] as $field_name=>$graph_name){
              $field_dictionary["$name.$field_name"] = $graph_name;
            }
          }
        }
      }
    }
    return $field_dictionary;
  }

  public static function getShortHand(){
    $cid = "graphql_shorthand_document";
    if ($cache = \Drupal::cache()->get($cid)) {
      return $cache->data;
    }
    else {
      $type_dictionary = Parser::getTypeDictionary("type_name");
      $field_dictionary =  Parser::getFieldDictionary();
      $plural_dictionary = Parser::getTypeDictionary("plural_name");
      $resolver_dictionary = Parser::getTypeDictionary("resolver_class");
      $connection_dictionary = Parser::getTypeDictionary("connections");

      $queries = "";
      $viewers = "";
      $mutations = "";
      $connection_types = "";
      $result->types = array();


      $entities =  \Drupal::entityManager()->getAllBundleInfo();
      foreach($entities as $entity_name=>$entity_list){
        foreach($entity_list as $name=>$value){
          if (!isset($type_dictionary[$name])){
            unset($entity_list[$name]);
          }
        }
        foreach($entity_list as $name=>$value){
          if (!isset($result->types[$name])){
            $result->types[$name] = array();
          }
          $ids = \Drupal::entityQuery('field_config')
            ->condition('id', "$entity_name.$name.", 'STARTS_WITH')
            ->execute();
          $field_configs = FieldConfig::loadMultiple($ids);
          foreach ($field_configs as $key=>$field_instance) {
            $combined_field_name = $name.".".$field_instance->getName();
            if (!isset($field_dictionary[$combined_field_name])){
                unset($field_configs[$key]);
            }
          }
          foreach ($field_configs as $field_instance) {
            $r = new \stdClass();
            $r->label = $field_instance->getLabel();
            $r->type = $field_instance->getType();
            if ($r->type == "entity_reference"){
              $settings = $field_instance->getSettings();

              if (isset($settings["target_type"])&&($settings["target_type"] == "user")){
                $r->reference_type = 'user';
              }
              elseif (isset($settings["handler_settings"]["target_bundles"])){
                $allowed_nodes = $settings["handler_settings"]["target_bundles"];
                foreach($allowed_nodes as $node_name=>$also_node_name){
                  $r->reference_type = $node_name;
                }
                if (count($allowed_nodes) != 1){
                  print_r($allowed_nodes);
                  die("can't handle multiple allowed nodes yet: ($r->label)");
                }
              }
            }
            $r->isRequired = $field_instance->isRequired();
            $cardinality = $field_instance->getFieldStorageDefinition()->getCardinality();
            $r->isList = ($cardinality == 1)?false:true;
            $result->types[$name][$field_instance->getName()] = $r;
          }
        }
      }
      $type_doc = "";

      $query_dictionary = Parser::getTypeDictionary("query_name");
      foreach($result->types as $name=>$type){
        $type_name = $type_dictionary[$name];
        $query_name = $query_dictionary[$name];// strtolower(substr($type_name,0,1)) . substr($type_name,1,strlen($type_name));
        $plural_name = $plural_dictionary[$name];

        if (isset($connection_dictionary[$name])){
          if (($connection_dictionary[$name] == "GRAPH") || ($connection_dictionary[$name] == "BOTH")){
            $connection_types .="\ntype $type_name"."Edge {\n\tcursor: String!\n\tnode: $type_name\n}\n";
            $connection_types .="type $type_name"."Connection {\n\tedges: [".$type_name."Edge]\n\tpageInfo: PageInfo!\n}\n";
          }
        }


        if (isset($resolver_dictionary[$name])){
          $resolver_class = $resolver_dictionary[$name];
          $test = new $resolver_class();
          $ops = $test->getOperations();
          foreach($ops["mutations"] as $mutation_name=>$mutation_details){
            $mutations .= "\t$mutation_name";
            $mutation_args = array();
            foreach($mutation_details["args"] as $arg_name=>$arg_type){
              $mutation_args[] = "$arg_name: $arg_type";
            }

            if (count($mutation_args) > 0){
                $mutations .= "(".implode(",",$mutation_args).")";
            }
            $mutations .= ": ".$mutation_details["return"]."\n";
          }

        }
        $queries .= "\t$query_name(id:ID!): $type_name\n";

        if (isset($resolver_dictionary[$name])){
          $resolver = new $resolver_dictionary[$name]();
          $ops = $resolver->getOperations();
          $args = $ops["args"];


          $viewer_name .= "\t$plural_name";
          $viewer_args = array();
          foreach($args as $arg_name=>$arg_type){
            $viewer_args[] = "$arg_name: $arg_type";
          }

          if (count($viewer_args) > 0){
              $viewer_name .= "(".implode(",",$viewer_args).")";
          }
          $viewer_name .= ": [$type_name]\n";
          $viewers .= $viewer_name;
        }
        else{
          $viewers .= "\t$plural_name(ids:[ID]): [$type_name]\n";
        }

        if (isset($connection_dictionary[$name])){
          if (($connection_dictionary[$name] == "GRAPH") || ($connection_dictionary[$name] == "BOTH")){
              $viewers .= "\t$query_name"."Connection(before: String, after: String, first: Int, last: Int): $type_name"."Connection\n";
          }
        }


        $type_doc .="type $type_name : Node {\n\tid: ID!\n\tname: String!\n";
        foreach($type as $field_name=>$field){
          $field_type=$field->type;
          switch($field->type){
            case "entity_reference":
              $field_type = $field->reference_type;
              break;
            case "boolean":
              $field_type = "Boolean";
              break;
            case "integer":
              $field_type = "Int";
              break;
            case "float":
              $field_type = "Float";
              break;
            case "image";
            case "list_string":
            case "text_with_summary":
            case "text":
              $field_type = "String";
              break;
            default:
          }
          $drupal_type = $field_type;
          if (isset($type_dictionary[$field_type])){
            $field_type = $type_dictionary[$field_type];
          }
          $base_field_type = $field_type;
          if ($field->isList){
            $field_type = "[$field_type]";
          }
          if ($field->isRequired){
            $field_type .= "!";
          }
          $new_name = $field_dictionary["$name.$field_name"];
          $connection="";
          if ($field->isList && ($base_field_type != "String")){
            $connection = "\t$new_name"."Connection(before: String, after: String, first: Int, last: Int): $base_field_type"."Connection\n";
          }
          $type_doc.= "\t$new_name: $field_type\n";
          if (($connection_dictionary[$drupal_type] == "GRAPH") || ($connection_dictionary[$drupal_type] == "BOTH")){
            $type_doc.= $connection;
          };

          $connection = "";
        }
        $type_doc .="}\n";
      }
      $page_info = "type PageInfo {\n\thasNextPage: Boolean!\n\thasPreviousPage: Boolean!\n\tstartCursor: String\n\tendCursor: String\n}\n";
      $type_doc = "interface Node {\n\tid: ID!\n}\n$page_info\n$type_doc$connection_types\ntype Viewer {\n$viewers\n}\n";

      $type_doc .= "type Query {\n$queries\tviewer : Viewer\n\tnode(id:ID!): Node\n}";
      if ($mutations != ""){
        $type_doc .="\ntype Mutation {\n$mutations\n}\n";
      }

      \Drupal::cache()->set($cid, $type_doc);
    }
    return $type_doc;
  }


  private static function parseTypeFieldsRaw($fields_string){
  	$fields_string = str_replace(": ",":",$fields_string);
  	$fields_string = str_replace(" :",":",$fields_string);
  	preg_match_all("/\((.*?)\)/",$fields_string,$parameter_strings);
  	$replacements = array();
  	for($i = 0; $i< count($parameter_strings[0]); $i++){
  		$fields_string = str_replace($parameter_strings[0][$i],"",$fields_string);
  	}
  	$tokens = explode(" ",$fields_string);
  	$fields = array();
  	$return = array();
  	foreach($tokens as $token){
  		$details = explode(":",$token);
  		$return[$details[0]] = $details[1];
  	}
  	return $return;
  }
  private static function parseTypeFields($fields_string, $known_types, $name = ""){//}, $self_reference){
  	global $graph_ql_self_references;

  	$fields_string = str_replace(": ",":",$fields_string);
  	$fields_string = str_replace(" :",":",$fields_string);
  	preg_match_all("/\((.*?)\)/",$fields_string,$parameter_strings);
  	$replacements = array();
  	for($i = 0; $i< count($parameter_strings[0]); $i++){
  		$replacements[$i] = $parameter_strings[1][$i];
  		$fields_string = str_replace($parameter_strings[0][$i],"REPLACEMENT_".$i."_REPLACEMENT",$fields_string);
  	}
  	$tokens = explode(" ",$fields_string);
  	$fields = array();
  	foreach($tokens as $token){
  		$details = explode(":",$token);
  		$field_name = $details[0];
  		if (strpos($field_name,"REPLACEMENT_") > 0){
  			preg_match_all("/REPLACEMENT_(.*?)_REPLACEMENT/", $field_name, $matches);
  			if (count($matches[0]) == 1){
  				$index = $matches[1][0];
  				$arg_string = $replacements[$index];
  				$args = Parser::parseTypeFields($arg_string,$known_types);
  				if (is_object($args)){
  					$field_name = str_replace("REPLACEMENT_".$index."_REPLACEMENT","",$field_name);
  					$type = $details[1];
  					$fields[$field_name] = array('args'=>$args, 'type' => Parser::setType($type, $known_types));
  				}
  				else{
  					$arg_array = array();
  					foreach($args as $arg_name=>$arg){
  						$arg_array[] = array("name"=>$arg_name,"description"=>"","type"=>$arg["type"]);
  					}
  					$field_name = str_replace("REPLACEMENT_".$index."_REPLACEMENT","",$field_name);
  					$type = $details[1];
  					$fields[$field_name] = array('args'=>$arg_array, 'type' => Parser::setType($type, $known_types));
  				}
  			}
  		}
  		else{
  			if (!isset($details[1])){
  				if (substr(strtolower(trim($fields_string)),0,5)== "input"){
  					$type_def = trim(substr($fields_string,5,strlen($fields_string)));

  				}
  				die("array 1 is missing: " . print_r($details,true));
  			}
  			$type = $details[1];
  			$rawType = str_replace("[","",$type);
  			$rawType = str_replace("]","",$rawType);
  			$rawType = str_replace("!","",$rawType);
  			if ($name == $rawType){
  				$graph_ql_self_references[$name][] = array("field"=>$field_name,"definition"=>$type);
  			}
  			else{
  				$fields[$field_name] = array('type' => Parser::setType($type, $known_types));
  			}
  		}
  	}
  	return $fields;
  }
  private static function setType($typeString, $known_types){
  	if (substr($typeString,strlen($typeString) - 1,1) == "!"){

  		$newType = substr($typeString,0,strlen($typeString) - 1);

  		return Type::nonNull(Parser::setType($newType, $known_types));
  	}
  	elseif (substr($typeString,strlen($typeString) - 1,1) == "]"){

  		$newType = substr($typeString,1,strlen($typeString) - 2);
  		$newType = Parser::setType($newType, $known_types);
  		if ($newType == null){
  			return null;
  		}
  		return Type::listOf($newType);
  	}
  	if ($typeString == "String"){
  		return Type::string();
  	}
  	if ($typeString == "string"){
  		return Type::string();
  	}
  	if ($typeString == "Int"){
  		return Type::int();
  	}
    if ($typeString == "Float"){
  		return Type::float();
  	}
  	if ($typeString == "Boolean"){
  		return Type::boolean();
  	}
  	if ($typeString == "ID"){
  		return Type::id();
  	}
  	if (isset($known_types[$typeString])){
  		return $known_types[$typeString];
  	}
  	else{
  		//PHP closure necessary to look up types that do not exist.
  		//Probably won't work for self-referencing types.
  		return function() use($typeString){
  			global $global_types;
  			if (isset($global_types[$typeString])){
  				return $global_types[$typeString];
  			}
  		};
  	}
  	return $typeString;
  }

  public static function parseSchemaShorthand($typeDoc){
  	global $graph_ql_self_references, $global_types;
  	$typeDoc = str_replace("\r","",$typeDoc);
  	$typeDoc = str_replace("\n"," ",$typeDoc);
  	$typeDoc = str_replace("\t"," ",$typeDoc);
  	$typeDoc = str_replace(","," ",$typeDoc);
  	while(strpos($typeDoc,"  ")!== false){
  		$typeDoc = trim(str_replace("  "," ",$typeDoc));
  	}
  	preg_match_all("/(type|enum|interface|input) (.*?)\{(.*?)\}/s",$typeDoc,$type_strings);
  	$type_definitions = array();
  	for ($i = 0; $i < count($type_strings[0]); $i++){
  		$type_definitions[] = array(
  			"type_kind"=>trim($type_strings[1][$i]),
  			"name_string"=>trim($type_strings[2][$i]),
  			"fields_string"=>trim($type_strings[3][$i])
  		);
  	}
  	$known_types = array();
  	$field_def_data = array();
  	foreach($type_definitions as $type_def){
  		$name = $type_def["name_string"];
  		$field_def_data[$name] = $type_def["fields_string"];
  		$interfaces = "";
  		if (strpos($name,":") > 0){
  			$name_parts = explode(":",$name);
  			$name = trim($name_parts[0]);
  			$interfaces = trim($name_parts[1]);
  		}
  		switch($type_def["type_kind"]){
  			case "enum":
  				$enum_strings = explode(" ",$type_def["fields_string"]);
  				$enum_values = array();
  				foreach($enum_strings as $string){
  					$enum_values[$string] = array();
  				}
  				$known_types[$name] = new EnumType([
  					'name' => $name,
  					'values' => $enum_values
  				]);
  				break;
  			case "interface":
  				$type_fields = Parser::parseTypeFields($type_def["fields_string"], $known_types);
  				$known_types[$name] = new InterfaceType([
  					'name' => $name,
  					'fields' => $type_fields
  				]);
  				break;
  			case "input":
  				$type_fields = Parser::parseTypeFields($type_def["fields_string"], $known_types);
  				$known_types[$name] = new InputObjectType([
  					'name' => $name,
  					'fields' => $type_fields
  				]);
  				break;
  			case "type":
  				$type_fields = Parser::parseTypeFields($type_def["fields_string"], $known_types, $name);
  				if ((isset($known_types[$interfaces])) && (get_class($known_types[$interfaces]) == "GraphQL\Type\Definition\InterfaceType")){
  					$objectValue = new ObjectType([
  						'name' => $name,
  						'fields' => $type_fields,
  						'interfaces' => [$known_types[$interfaces]]
  					]);
  				}
  				else{
  					$objectValue = new ObjectType([
  						'name' => $name,
  						'fields' => $type_fields
  					]);
  				}
  				$known_types[$name] = $objectValue;

  			break;
  		}
  		foreach($known_types as $type_name => $type_data){
  			if (!isset($field_def_data[$type_name])){
  				foreach($field_def_data as $key=>$value){
  					$tn = str_replace(" ","",$type_name);
  					if (substr($key,0,strlen($tn)+1) == "$type_name:"){
  						$field_data = Parser::parseTypeFieldsRaw($value);
  						foreach($field_data as $field_name=>$def_string){
  							$def_string_base = str_replace("!","",$def_string);
  							$def_string_base = str_replace("[","",$def_string_base);
  							$def_string_base = str_replace("]","",$def_string_base);
  							if ($def_string_base == $name){
  								$new_type = Parser::setType($def_string,$known_types);
  								$existing_field = $known_types[$type_name]->getField($field_name);
  								$existing_field =$new_type;
  							}
  						}
  					}
  				}
  			}
  			else{

  				$field_data = Parser::parseTypeFieldsRaw($field_def_data[$type_name]);

  				foreach($field_data as $field_name=>$def_string){
  					$def_string_base = str_replace("!","",$def_string);
  					$def_string_base = str_replace("[","",$def_string_base);
  					$def_string_base = str_replace("]","",$def_string_base);
  					if ($def_string_base == $name){
  						$new_type = Parser::setType($def_string,$known_types);
  						$existing_field = $known_types[$type_name]->getField($field_name);
  						$existing_field = $new_type;
  					}
  				}
  			}
  		}
  		$global_types = $known_types;
  	}
  	foreach($graph_ql_self_references as $type_name=>$array){
  		foreach($array as $field){
  			$new_field = Parser::setType($field["definition"],$known_types);
  			$known_types[$type_name]->setField($field["field"],$new_field);
  		}
  	}
  	return $known_types;
  }
}
?>
