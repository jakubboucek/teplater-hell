<?php

class TemplaterSyntaxException extends LogicException {}
class TemplaterNotFoundException extends RuntimeException {}


class Templater extends Object {
  const ELEMENT_IDENTIFICATOR = '§';

  const NODE_TYPE_SCALAR = 0;
  const NODE_TYPE_NODE = 1;

  const NODE_CLONE_ONLY = 0;
  const NODE_CLONE_RECURSIVE = 1;
  const NODE_CLONE_APPEND_AFTER = 2;

  static private $autoincrement = 0;
  private $guid;
  private $nodeName = NULL;
  private $parentNode = NULL;
  private $childNodes = array();
  private $nodeAttributs = array();
  private $id = NULL;


  public function __construct($nodeName) {
    $this->guid = self::$autoincrement++;
    $this->nodeName = $nodeName;
  }

  //Reset unicate items
  public function __clone() {
    $this->guid = self::$autoincrement++;
    $this->parentNode = NULL;
    $this->childNodes = array();
    $this->id = NULL;
  }

  //Reset unicate items
  public function __destruct() {
    $this->clear();
    if($this->parentNode instanceof self)
      $this->parentNode->removeNode($this);
  }

  //Vytvoření šablony ze souboru
  static public function parseFile($filename) {
    if(file_exists($filename)) {
      return self::parseCode(file_get_contents($filename));
    }
    throw new FileNotFoundException("Soubor šablony $filename nebyl nalezen.");
  }

  /** Vytvoření šablony ze souboru
   *
   * @param string $rootNodeName
   * @param string $filename
   * @return Templater
   */
  static public function byFile($rootNodeName,$filename) {
    if(file_exists($filename)) {
      return self::byCode($rootNodeName, file_get_contents($filename));
    }
    throw new FileNotFoundException("Soubor šablony $filename nebyl nalezen.");
  }

  static public function byCode($rootNodeName, $code, $id = NULL) {
    $newTeplater = new self($rootNodeName);

    $newTeplater->id = $id;

    $childs = self::parseCode($code);

    $newTeplater->appendNode($childs);

    return $newTeplater;
  }

  //Vytvoření šablony ze souboru
  static public function parseCode($code) {
    //První obalující objekt
    $segmentStack = array(new self('temporaryTemplater'));
    $codeSegmentMatch = NULL;
    $codeSegmentAtributs = array();

    //Nasekání kódu na segmenty
    $codeSegments = preg_split('#(<' . self::ELEMENT_IDENTIFICATOR . '/?[a-z_]+[^>]*>)#', $code, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

    foreach($codeSegments as $codeSegment) {

      //Zkoumání segmentů
      //Pokud je segment značkou šablony (zároveň rozparsuje značku na název a atributy)
      if(preg_match('#^<' . self::ELEMENT_IDENTIFICATOR . '(/?)([a-z_]+)([^>]*)>$#', $codeSegment, $codeSegmentMatch)) {

        //Pokud NENÍ značka ukončovací
        if(empty($codeSegmentMatch[1])) {
          //Přidání značky do zásobníku
          $newNode = new self($codeSegmentMatch[2]);
          end($segmentStack)->appendNode($newNode);
          array_push($segmentStack, $newNode);

          //Rozložení atributů
          if(preg_match_all('#([a-z0-9_]+)(?:="([^"]*)")?#', $codeSegmentMatch[3], $codeSegmentAtributs)) {
            for($i=0;$i<count($codeSegmentAtributs[0]);$i++) {
              //Pridíní adributu
              if($codeSegmentAtributs[1][$i] == 'id')
                end($segmentStack)->Id = $codeSegmentAtributs[2][$i];
              else
                end($segmentStack)->setAttribut($codeSegmentAtributs[1][$i], html_entity_decode($codeSegmentAtributs[2][$i]));
            }
          }
        }

        //Detekce ukončovacího znaku v nepárové značce
        if(!empty($codeSegmentMatch[3]) && preg_match('#/$#', $codeSegmentMatch[3])) {
          //Vyhodit ze zásobníku
          array_pop($segmentStack);
        }

        //Pokud je značka ukončovací
        if(!empty($codeSegmentMatch[1])) {
          if(count($segmentStack)<=1)
            throw new TemplaterSyntaxException("Je zavřeno příliš mnoho značek.");
          $poped_item = array_pop($segmentStack);
          //Pokud není splněna podmínka že:
          //    objekt      JE instancí      a   název značky          je názvem značky
          if(!(($poped_item instanceof self) && ($poped_item->nodeName == $codeSegmentMatch[2])))
            throw new TemplaterSyntaxException("Prvky se neshodují ($codeSegmentMatch[2]).");
        }
      }
      //Pokud je segment pouze textem
      else {
        end($segmentStack)->appendNode($codeSegment);
      }
    }
    if(count($segmentStack)!=1)
      throw new TemplaterSyntaxException("Zůstaly neuzavřené značky.");

    return reset($segmentStack)->childNodes;
  }

  static public function make($name, $value, $id = NULL) {
    $my = new self($name);
    $my->appendNode($value);
    if($id !== NULL)
      $my->id = $id;
    return $my;
  }

  static public function compare($first, $second) {
    if($first instanceof self && $second instanceof self && $first->guid==$second->guid)
      return TRUE;
    elseif($first === $second)
      return TRUE;

    return FALSE;
  }

  public function appendNode($node, $after=NULL) {
    if(is_array($node)) {
      foreach($node as $noteitem) {
        $this->appendNode($noteitem);
      }
      return $node;
    }
    elseif($node instanceof self) {
      //Drop node from other place
      if($node->parentNode instanceof self)
        $node->parentNode->removeNode($node);
      $node->parentNode = $this;
    }

    //Pokud se vkládá na konec
    if($after === NULL) {
      array_push($this->childNodes, $node);
      return $node;
    }
    //Pokud se vkládá na začátek (v $after je $this)
    elseif(self::compare($this, $after)) {
      array_unshift($this->childNodes, $node);
      return $node;
    }
    //Pokud se vkládá určený
    else {
      //Nový zásobník
      $newChildNodes = array();
      //Projde všechny od začátku a postupně je odebírá z původní kolekce
      while($childNode = array_shift($this->childNodes)) {
        //Přisype do nové kolekce
        array_push($newChildNodes, $childNode);
        //Pokud najde shodu
        if(self::compare($childNode, $after)) {
          //Kolekci potomků doplní v pořadí, aby appendovaný potomek byl za $after
          $this->childNodes = array_merge($newChildNodes, array($node), $this->childNodes);
          //Konec funkce
          return $node;
        }
      }

      //Řešení problému, pokud nebyla nalezena shoda, je třeba vynulovat parenta (protožen není součástí kolekce)
      if($node instanceof self)
        $node->parentNode = NULL;
    }
    //Sem by se kód neměl dostat
    return NULL;
  }

  public function removeNode($node) {
    $nodeIsSelf = $node instanceof self;
    foreach($this->childNodes as $childKey=>$childNode) {
      if($nodeIsSelf && $childNode instanceof self) {
        if($childNode->guid == $node->guid) {
          unset($this->childNodes[$childKey]);
          $childNode->parentNode = NULL;
          return $childNode;
        }
      }
      elseif(!($nodeIsSelf || $childNode instanceof self)) {
        if($childNode === $node) {
          unset($this->childNodes[$childKey]);
          return $childNode;
        }
      }
    }
    return NULL;
  }

  //Osamostatnit (utrhnout se rodičům)
  public function grabByParent() {
    if($this->parentNode)
      $this->parentNode->removeNode($this);

    return $this;
  }

  public function setAttribut($name,$value) {
    $this->nodeAttributs[$name] = $value;

    return $this;
  }

  public function getAttribut($name,$value=NULL) {
    if(array_key_exists($name, $this->nodeAttributs))
      return  $this->nodeAttributs[$name];
    else
      return $value;
  }

  public function getNodeName() {
    return $this->nodeName;
  }

  public function getId() {
    return $this->id;
  }

  public function setId($id) {
    $this->id = $id;
  }

  public function getGuid() {
    return $this->guid;
  }

  public function getParentNode() {
    return $this->parentNode;
  }

  public function __toString() {
    $buff = "";
    foreach($this->childNodes as $childNode) {
      if($childNode instanceof self && !$childNode->getAttribut('display',TRUE))
        continue;
      $buff .= $childNode;
    }
    return $buff;
  }

  public function clear() {
    foreach($this->childNodes as $childNode)
      if($childNode instanceof self)
        $childNode->parentNode = NULL;
    $this->childNodes = array();

    return $this;
  }

  public function cloneNode($clone_flag = self::NODE_CLONE_RECURSIVE) {
    $newself = clone $this;
    if($clone_flag & self::NODE_CLONE_RECURSIVE) {
      foreach($this->childNodes as $childNode) {
        if($childNode instanceof self)
          $newself->appendNode($childNode->cloneNode(self::NODE_CLONE_RECURSIVE));
        else
          $newself->appendNode($childNode);
      }
    }
    if($clone_flag & self::NODE_CLONE_APPEND_AFTER) {
      if($this->parentNode)
        //die('se');
        $this->parentNode->appendNode($newself, $this);
    }
    return $newself;
  }

  public function getChildNodes($type = NULL) {
    if($type == self::NODE_TYPE_NODE) {
      $p = array();
      foreach($this->childNodes as $childNode)
        if($childNode instanceof self)
          $p[] = $childNode;
      return $p;
    }
    else {
      return $this->childNodes;
    }
  }

  public function getInfo() {
    $return = array(
      'guid' => $this->guid,
      'id' => $this->id,
      'nodeName' => $this->nodeName,
      'nodeType' => self::NODE_TYPE_NODE,
      'hasParent' => (bool)($this->parentNode),
      'attributs' => $this->nodeAttributs,
      'childInfo' => $this->getChildInfo(),
    );
    return $return;

  }

  public function getChildInfo() {
    $return = array();

    foreach($this->childNodes as $childNode) {
      if($childNode instanceof self) {
        $return[] = array(
          'guid' => $childNode->guid,
          'id' => $childNode->id,
          'nodeName' => $childNode->nodeName,
          'nodeType' => self::NODE_TYPE_NODE,
          'hasParent' => (bool)($childNode->parentNode),
          'attributs' => $childNode->nodeAttributs,
          'childInfo' => $childNode->getChildInfo(),
        );
      }
      else {
        $return[] = array(
          'guid' => NULL,
          'id' => NULL,
          'nodeName' => NULL,
          'nodeType' => self::NODE_TYPE_SCALAR,
          'hasParent' => NULL,
          'attributs' => NULL,
          'childInfo' => NULL,
        );
      }

    }

    return $return;
  }

  public function setValue($value) {
    $this->clear();
    $this->appendNode($value);
  }

  //Return only string value
  public function getValue() {
    return (string) $this;
  }

  /**
   *
   * @param string $id
   * @return \self
   * @throws TemplaterNotFoundException
   */
  public function getElementById($id) {
    foreach($this->childNodes as $childNode)
      if($childNode instanceof self) {
        try {
          if($childNode->id == $id)
            return $childNode;
          elseif($childNode = $childNode->getElementById($id))
            return $childNode;
        }
        catch(TemplaterNotFoundException $e) {}
      }
    throw new TemplaterNotFoundException("Element $id nebyl nalezen.");
  }

  public function setParam($name, $value) {
    foreach($this->getChildNodes(self::NODE_TYPE_NODE) as $childNode) {
      if($childNode->nodeName = 'param' &&  $childNode->getAttribut('name') == $name) {
        $childNode->value = $value;
        //Pokud je hodnota objekt nebo pole, lze dosadit pouze do prvního výskytu. Ostatní zaplní všechny hodnoty
        if($value instanceof self || is_array($value))
          return $this;
      }
    }
    return $this;
  }

  public function setParamsByArr($paramsSet) {
    foreach($paramsSet as $paramKey=>$paramItem)
      $this->setParam($paramKey, $paramItem);
    return $this;
  }

  public function getParam($name) {
    foreach($this->getChildNodes(self::NODE_TYPE_NODE) as $childNode) {
      if($childNode->nodeName = 'param' &&  $childNode->getAttribut('name') == $name) {
        return $childNode;
      }
    }
    throw new TemplaterNotFoundException("Paramtr $name nebyl nalezen.");
  }

  public function setRadioCheck($name) {
    $activeCheck = NULL;

    foreach($this->getChildNodes(self::NODE_TYPE_NODE) as $childNode) {
      if($childNode->nodeName == 'radio') {
        if($childNode->getAttribut('name') == $name) {
          $childNode->setAttribut('display',1);
          $activeCheck = $childNode;
        }
        else
          $childNode->setAttribut('display',0);
      }
    }
    return $activeCheck;
  }
}
