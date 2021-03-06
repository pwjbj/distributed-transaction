<?php
namespace Larasaas\DistributedTransaction;

//use Illuminate\Support\Facades\Log;
use Larasaas\DistributedTransaction\Models\Transaction;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Helper\Protocol\Wait091;

//define('AMQP_PROTOCOL','0.8');
//define('AMQP_DEBUG', true);
class ConfirmEmit implements DTS
{

    protected $connection;
    protected $channel;
    protected $queue;
    protected $callback_queue;
    protected $exchange;


    public function __construct()
    {
        try{
            $this->connection = new AMQPStreamConnection(
                config('dts.confirm.mq_host','localhost'),      // 'localhost',
                config('dts.confirm.mq_port',5672),      //  5672,
                config('dts.confirm.mq_user','guest'),      // 'guest',
                config('dts.confirm.mq_password','guest')   // 'guest'
            );

        }catch (\ErrorException $e){
            exit('rabbitmq连接错误');
        }

        $this->channel = $this->connection->channel();


//        $this->channel->tx_select();
        $this->channel->confirm_select();     //消息确认模式
//
//        $this->exchange = $this->channel->exchange_declare(
//            config('transaction.receive.exchange.name','topic_message'),        // 'topic_message'
//            config('transaction.receive.exchange.type','topic'),        // 'topic'
//            config('transaction.receive.exchange.passive',false),     // false
//            config('transaction.receive.exchange.durable',true),     // false
//            config('transaction.receive.exchange.auto_delete',false)  // false
//        );
        $this->queue = $this->channel->queue_declare(
            config('dts.confirm.queue.queue',"confirm_queue"),      // ""
            config('dts.confirm.queue.passive',false),    // false
            config('dts.confirm.queue.durable',true),    // false
            config('dts.confirm.queue.exclusive',false),  // true
            config('dts.confirm.queue.auto_delete',false) // false
        );

    }

    public function set_ack_handler(\Closure $closure){
//        $closure = function (AMQPMessage $message) {
//            echo "(".$message->delivery_info['delivery_tag'].")Message acked with content " . $message->body . PHP_EOL;
//        };
        $this->channel->set_ack_handler($closure);

    }

    public function set_nack_handler(\Closure $closure)
    {
//        $closure = function (AMQPMessage $message) {
//            echo "(".$message->delivery_info['delivery_tag'].")Message nacked with content " . $message->body . PHP_EOL;
//        };
        $this->channel->set_nack_handler($closure);
    }


    public function set_return_listener(\Closure $closure)
    {
//        $closure = function ($replyCode, $replyText, $exchange, $routingKey, AMQPMessage $message) {
////                echo "Message returned with content " . $message->body . PHP_EOL;
//            echo "return: ",
//            $replyCode, "\n",
//            $replyText, "\n",
//            $exchange, "\n",
//            $routingKey, "\n",
//            $message->body, "\n";
//        };
        //当exchange与queue未绑定时候，则会触发。
        $this->channel->set_return_listener($closure);
    }


    /**
     * 提交前，需要保存事务消息
     * @param array $message_data
     * @return mixed
     */
    public function save_message($trans_data=[])
    {
        $message_data = $trans_data['message'] ?? '';
        if(empty($message_data)){
            return ['error'=>1,'message'=>'事务消息体错误','data'=>$message_data];
        }
//        $trans_data['message']=$message_data;
        $message = Transaction::create($trans_data);
        if(! $message){
            return ['error'=>1,'message'=>'保存事务消息失败','data'=>$message_data];
        }

        return ['error'=>0,'message'=>'ok','data'=>$message];

    }

    private function getApiToken()
    {
        $request=request();
        if(isset($request->api_token) && !empty($request->api_token)){
            return $request->api_token;
        }
        $authorization=$request->header('authorization');
        if( $authorization && substr($authorization,0,7)==='Bearer '){
            return substr($authorization,7);
        }
        return false;
    }

    /**
     * 本地事务提交成功后，向Message Queue请求发送事务消息
     * @param $message 消息entity
     */
    public function send_message($message,$props=[
        'content_type' => 'text/plain',   //'application/json',
        'delivery_mode' => 2,
        ''
    ])
    {

        $producer = $message->producer; //生产者，需要验证
        $routing_key=$message->consumer;   //services.setting.brandcreator;消费者
        $api_token=$this->getApiToken();
        $user_id=$message->user_id;
        $msg_body=$message->message;
        $trans_id=$message->id;
        $msg = new AMQPMessage(json_encode([
            'api_token'=>$api_token,
            'user_id'=>$user_id,
            'producer'=>$producer,
            'msg_body'=>$msg_body,
            'trans_id'=>$trans_id
        ]),$props);
        $this->channel->basic_publish($msg, config('dts.confirm.exchange.name','confirm_exchange'), $routing_key,false);

        $this->channel->wait_for_pending_acks();  //不用等返回
//        $this->channel->wait_for_pending_acks_returns();    //如果路由错误，则会触发set_return_listener.

        $message->status=1;
        $return = $message->save();
        if(! $return) {
            return ['error'=>1,'message'=>'发送事务消息失败','data'=>$message];
        }
        return ['error'=>0,'message'=>'ok','data'=>$message];
    }



    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }

}