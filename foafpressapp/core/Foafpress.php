<?php

class Foafpress extends SandboxPlugin
{

    public $URI_Request = null;
    public $URI_Document = null;
    
    public $extensiontype = null;

    public $config = array();
    
    public $base = null;
    
    public $arc2_resource = null;
    
    public $foaf_primaryTopic = null;
    
    public $arc2_exportfunctions = array(
                                            'application/rdf+xml' => 'toRDFXML',
                                            'text/turtle' => 'toTurtle',
                                            'text/plain' => 'toNtriples'
                                        );

    protected function init()
    {
        error_reporting(E_ALL); // set_time_limit(0);

        // check user configuration of namespaces
        if (!isset($this->config['ns']))
        {
            $this->config['ns'] = array();
        }

        // load standard configuration and add missing vars to user config
        if (is_readable($this->path.'Foafpress.config.php')) include_once($this->path.'Foafpress.config.php');
        if (isset($c))
        {
            foreach($c as $c_key => $c_value)
            {
                if (isset($this->config[$c_key]) && is_array($this->config[$c_key]))
                {
                    $this->config[$c_key] = array_merge($c[$c_key], $this->config[$c_key]);
                }
                elseif (!isset($this->config[$c_key]))
                {
                    $this->config[$c_key] = $c_value;
                }
            }
            //$this->print_r($this->config);
        }
        
        // $this->print_r($this->config);
        
        // add foafpress templates to template configuration
        $this->sandbox->templateAddFolder($this->path.'templates/');
        
        // add foafpress controllers to plugin configuration
        $this->sandbox->pm->addFolder($this->path.'controllers/');
        
        // Foafpress event handlers for SPCMS
        $this->pm->subscribe('sandbox_parse_failed', $this, 'FindResource'); // parameters: event name, class name or instance, event handler method
        $this->pm->subscribe('sandbox_parse_end', $this, 'LoadResourceFromFile'); // parameters: event name, class name or instance, event handler method
        
        // load ARC2
        $this->pm->need('./arc2/ARC2');
        
        // load ARC2 Template Object
        $this->pm->need('./rdfto/rdfto.arc2');
        
        // load Foafpress includes
        $this->pm->need(dirname(__FILE__).'/Foafpress.inc');
        $this->pm->need(dirname(__FILE__).'/store-adapters/Foafpress.Arc2File');
        
        // libaries folder for templates
        $this->content->FPLIBURL = str_replace(BASEDIR, BASEURL, realpath($this->config['arcbase'].'../'));
        
        
        return;
        
    }
    
    public function FindResource($file)
    {
        $extensions = $this->config['types'];
        
        $this->URI_Request = 'http://'.$_SERVER['SERVER_NAME'].str_replace(BASEDIR, BASEURL, $file);
        
        // check for request type by file extension
        
        //*
        foreach ($extensions as $type=>$ext)
        {
            if (substr($file, -1*strlen($ext)) == $ext)
            {
                $file = substr($file, 0, -1 * strlen($ext));
                $this->extensiontype = $type;
                break;
            }
        }
        //*/
        
        //die($this->URI_Request);
        
        // check for existing files
        
        foreach ($extensions as $ext)
        {
            if (is_readable($file.$ext))
            {
                $this->sandbox->parse($file.$ext);
                // LoadResourceFromFile method will be triggered automatically by event dispatcher
                
                return;
            }
        }
    
        // TODO: send 404 if no file/resource is available
        die($file.' is not found!');
        
        return;
    }
    
    public function LoadResourceFromFile($file)
    {
        // get url of rdf document
        $this->URI_Document = 'http://'.$_SERVER['SERVER_NAME'].str_replace(BASEDIR, BASEURL, $file);
        
        if ($this->URI_Request && $this->extensiontype)
            $this->URI_Document = $this->URI_Request;
        
        //die($this->URI_Document);
        
        if (false === ($index = $this->cache->getVar($this->URI_Document, 'Foafpress', time()-filectime($file), 0)))
        {
            // load arc2 parser
            $parser = ARC2::getRDFParser();
            // parse rdf document
            $parser->parse($this->URI_Document, $this->content->SANDBOX);
            // get rdf content as multi-indexed array
            $index = $parser->getSimpleIndex(0);
            $this->cache->saveVar($index, $this->URI_Document, 'Foafpress', true);
        }
        
        // load namespaces from config
        $namespaces = array();
        if (isset($this->config['ns'])) $namespaces = $this->config['ns'];
        
        // load rdf content as arc2 resource
        $this->arc2_resource = ARC2::getResource(array('ns'=>$namespaces));
        $this->arc2_resource->setIndex($index);
        
        $uri = $this->ResolveResourceRequest();
        
        //die($uri);

        // set shown resource
        $this->arc2_resource->setURI($uri);
        
        //*
        if ($exporttype = $this->isExportRequest())
        {
            if (isset($this->arc2_exportfunctions[$exporttype]))
            {
                $this->exportRdfData($exporttype);
            }
            else
            {
                $template_type = $this->config['types'][$exporttype];
            }
        }
        //*/
        
        if (!isset($template_type))
        {
            $template_type = $this->config['types'][$this->config['typefallback']];
        }

        // load Foafpress wrapper for arc2 resource
        $FP = new Foafpress_Resource_Arc2File(array('FP_config' => &$this->config,
                                           'spcms_cache' => &$this->cache,
                                           'spcms_pm' => &$this->pm
                                          )
                                    );

        $FP->initResource($this->arc2_resource); //$FP->initResource(&$this->arc2_resource);

        // set shown resource
        $FP->uri = $uri;
        
        // add sameAs resources
        if ($this->config['LinkedData']['followSameas'] == true)
            $FP->includeSameAs();
        
        // default namespace in Foafpress wrapper
        $concept = $FP->updateNamespacePrefix();

        // set layout
        if ($this->sandbox->templateSearch('Foafpress'.$template_type))
        {
            // change sandbox layout which was configured before
            $this->sandbox->templateSetLayout('Foafpress'.$template_type);
        }
        else
        {
            // TODO: throw exception
            die('Foafpress'.$template_type.'.php not found!');
        }

        // use ns:concept to set template and controller
        if ($concept !== false && $FP->ns_prefix)
        {
        
            // try to set template
            
            // search for template, its name is namespace/concept.tpl
            if ($this->sandbox->templateSearch($FP->ns_prefix.DIRECTORY_SEPARATOR.$concept.$template_type))
            {
                // change sandbox template which was configured before
                $this->sandbox->templateSetName($FP->ns_prefix.DIRECTORY_SEPARATOR.$concept.$template_type);
            }
            else
            {
                // TODO: throw exception
                die($FP->ns_prefix.DIRECTORY_SEPARATOR.$concept.$template_type.'.php not found!');
            }
            
            // try to set controller
            //*
            try
            {
                $action_controller_class_path = $this->pm->need($FP->ns_prefix.DIRECTORY_SEPARATOR.$concept);
                
                if (!isset($_SERVER['REQUEST_METHOD']) || !$_SERVER['REQUEST_METHOD'])
                {
                    throw new Exception('Empty request method!'); // TODO http error code
                }
                elseif (in_array($_SERVER['REQUEST_METHOD'], $this->config['supportedmethods']))
                {
                    $action_controller_class_name = ucfirst($FP->ns_prefix.'_'.$concept.'_Controller');
                    $action_controller_use_method = strtolower($_SERVER['REQUEST_METHOD']).'_request';
                    $action_controller = new $action_controller_class_name($this->sandbox, $action_controller_class_path);
                    
                    // execute controller request action with resource
                    $action_controller->add_resource_object($FP);
                    $action_controller->$action_controller_use_method();
                }
                else
                {
                    throw new Exception($_SERVER['REQUEST_METHOD'].' is not supported here!'); // TODO http error code
                }
            }
            catch(Exception $e)
            {
                throw $e;
            }
            // */
        }
        
        return;
    }
    
    /**
     * Resolve best resource from request
     *
     * Checks if the requested URI is drescribed in document and if not -- make
     * a best guess (check foaf:document about the described resource)
     */
    protected function ResolveResourceRequest()
    {
        $uri = $this->URI_Request;
        
        // requested URI is described in document
        
        if ($uri !== null && !$this->extensiontype && isset($this->arc2_resource->index[$uri]))
            return $uri;
            
        // requested URI is not described, check the document to resolve resource
        
        $uri = $this->URI_Document; // cannot be null
        
        if (isset($this->arc2_resource->index[$uri]))
        {
            // we have statements about the document in the index
            
            $checkTopicOfUri = $uri;
        }
        elseif ($xmlbase = stripos($this->content->SANDBOX, 'xml:base="'))
        {
            // no statement in index, check for xml:base definition
            
            $baseStart = substr($this->content->SANDBOX, $xmlbase+10);
            $xmlbase = substr($baseStart, 0, strpos($baseStart, '"'));
            
            if (isset($this->arc2_resource->index[$xmlbase]))
                $checkTopicOfUri = $xmlbase;
        }
        
        /*
        echo '<pre>'.($xmlbase?$xmlbase:$uri)."\n".'</pre>';
        echo '<pre>'.print_r($this->arc2_resource->index, true)."\n".'</pre>';
        $this->print_r($this->arc2_resource->index[$checkTopicOfUri]['http://xmlns.com/foaf/0.1/primaryTopic']);
        // */
        
        // check base url for primaryTopic predicate
        if (isset($checkTopicOfUri) && isset($this->arc2_resource->index[$checkTopicOfUri]['http://xmlns.com/foaf/0.1/primaryTopic']))
        {
            return $this->arc2_resource->index[$checkTopicOfUri]['http://xmlns.com/foaf/0.1/primaryTopic'][0]['value'];
        }
        else
        {
            // last fallback, use url of requested rdf document
            return $uri;
        }
        
        
    }
    
    protected function isExportRequest()
    {
        // if one of the RDF types is requested with q=1 then forward location to file
        if ($this->URI_Request && !$this->extensiontype && $type = $this->isRequestType(array_keys($this->config['types'])))
        {
            //die($this->URI_Request.' '.$type.' '.$this->URI_Request.$this->config['types'][$type]);
            //die('Location: '.$this->URI_Request.$this->config['types'][$type]);
            //header('Location: '.$this->URI_Document, true, 301); exit();
            header('Location: '.$this->URI_Request.$this->config['types'][$type], true, 301); exit();
        }
        
        // if RDF file is requested directly and the client can work with the
        // application type (or file extensions imply export, see Foafpress
        // config) then set export=true
        
        // virtuell existing RDF files
        if ($this->URI_Document && $this->URI_Document == $this->URI_Request &&
                                   (($this->config['extensiontoexport']) ||
                                    ($this->extensiontype && $this->isRequestType(array($this->extensiontype), true))
                                   )
           )
        {
            //die('export virtual file '.$this->extensiontype);
            return $this->extensiontype;
        }
        
        // for real existing RDF files
        if ($this->URI_Document && !$this->URI_Request)
        {
            foreach ($this->config['types'] as $type=>$ext)
            {
                if (substr($this->URI_Document, -1*strlen($ext)) == $ext)
                {
                    $extensiontype = $type;
                    break;
                }
            }

            if (isset($extensiontype) && ($this->config['extensiontoexport'] || $type = $this->isRequestType(array($extensiontype), true)))
            {
                //die('export real file '.$type);
                return $type;
            }
            
        }
        
        return false;
       
    }
    
    protected function isRequestType(Array $types, $soft = false)
    {
    
        if (!isset($this->possibleTypes))
        {
            $this->possibleTypes = array();

            // get accepted types
            $http_accept = trim($_SERVER['HTTP_ACCEPT']);
            
            // save accepted types in array
            $accepted = explode(',', $http_accept);
            
            if (count($accepted)>0)
            {
                // extract accepting ratio
                $test_accept = array();
                foreach($accepted as $format)
                {
                    $formatspec = explode(';',$format);
                    $k = trim($formatspec[0]);
                    if (count($formatspec)==2)
                    {
                        $test_accept[$k] = trim($formatspec[1]);
                    }
                    else
                    {
                        $test_accept[$k] = 'q=1.0';
                    }
                }
                
                // sort by ratio
                arsort($test_accept);
                
                $this->possibleTypes = $test_accept;
            }
        }
        
        //$this->print_r($test_accept);

        if ($this->possibleTypes)
        {
            $accepted_order = array_keys($this->possibleTypes);
            
            foreach ($types as $type)
            {
                if (isset($this->possibleTypes[$type]))
                {
                    // client says it can process the file type
                    
                    // softcheck
                    if ($soft === true) //die('softexport');
                        return $type;
                        
                    // hardcheck
                    if ($accepted_order[0] == $type || $this->possibleTypes[$type] == 'q=1.0')
                        return $type;
                }
                
            }
            
        }

        return false;

    }
    
    public function exportRdfData($type = null)
    {
        $exportfunction = $this->arc2_exportfunctions;
    
        if ($type)
        {
            $requesttype = $type;
        }
        elseif ($this->extensiontype)
        {
            $requesttype = $this->extensiontype;
        }
        else
        {
            foreach ($this->config['types'] as $type=>$ext)
            {
                if (substr($this->URI_Document, -1*strlen($ext)) == $ext)
                {
                    $requesttype = $type;
                    break;
                }
            }
        }
        
        header('Content-Type: '.$requesttype, true, 200);
        echo $this->arc2_resource->$exportfunction[$requesttype]($this->arc2_resource->index);
        exit();

    }

    public function print_r($array)
    {
        echo '<pre>';
        print_r($array);
        die('</pre>');
    }
}
