<?php
namespace Hoopak;

/**
 * 
 **/
class ZipkinTracer
{
    public function __construct($scribeClient, $category="zipkin")
    {
        $this->_scribe = $scribeClient;
        $this->_category = $category;
        $this->_annotationsForTrace = array();
        // TODO properly import thrift constants
        $this->_endAnnotations = array("cr", "ss");
    }

    public function sendTrace($trace, $annotations)
    {
        $thriftOut = $this->thriftToBase64($trace, $annotations);
        $this->_scribe->log($this->_category, $thriftOut);
    }

    public function record($trace, $annotation)
    {
        $traceKey = $trace->traceId . ":" . $trace->spanId;
        $this->_annotationsForTrace[$traceKey][] = $annotation;

        if (in_array($annotation->name, $this->_endAnnotations)) {
            $annotations = $this->_annotationsForTrace[$traceKey];
            unset($this->_annotationsForTrace[$traceKey]);
            print_r(sprintf("Sending trace: %s w/ %s", $traceKey, var_export($annotations, true)));
            $this->sendTrace($trace, $annotations);
        }
    }

    private function thriftToBase64($trace, $annotations)
    {
        $thriftAnnotations = array();
        $binaryAnnotations = array();

        foreach ($annotations as $annotation) {
            $host = null;
            if ($annotation->endpoint) {
                $host = new \Zipkin\Endpoint(
                    array(
                        "ipv4" => ip2long($annotation->endpoint->ipv4),
                        "port" => $annotation->endpoint->port,
                        "service_name" => $annotation->endpoint->serviceName,
                    )
                );
            }

            if ($annotation->annotationType == 'timestamp') {
                $thriftAnnotations[] = new \Zipkin\Annotation(
                    array(
                        "timestamp" => $annotation->value,
                        "value" => $annotation->name,
                        "host" => $host
                    )
                );
            } else {
                $type = constant('\Zipkin\AnnotationType::' . strtoupper($annotation->annotationType));
                $binaryAnnotations[] = new \Zipkin\BinaryAnnotation(
                    array(
                        "key" => $annotation->name,
                        "value" => $annotation->value,
                        "annotation_type" => $type,
                        "host" => $host
                    )
                );
            }
        }

        $thriftTrace = new \Zipkin\Span(
            array(
                "trace_id" => $trace->traceId,
                "name" => $trace->name,
                "id" => $trace->spanId,
                "parent_id" => $trace->parentSpanId,
                "annotations" => $thriftAnnotations,
                "binary_annotations" => $binaryAnnotations
            )
        );

        $trans = new \Thrift\Transport\TMemoryBuffer();
        $proto = new \Thrift\Protocol\TBinaryProtocol($trans);

        $thriftTrace->write($proto);

        return base64_encode($trans->getBuffer());
    }
}
