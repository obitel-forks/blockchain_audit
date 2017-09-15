<?php
// web/index.php
use Silex\Provider\FormServiceProvider;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../vendor/autoload.php';

$app = new Silex\Application();

$app['locale'] = 'uk';

$app
    ->register(new Silex\Provider\MonologServiceProvider(), array(
        'monolog.logfile' => __DIR__.'/../app.log',
))
    ->register(new Silex\Provider\TwigServiceProvider(), array(
        'twig.path' => __DIR__.'/../views',
        'twig.form.templates' => ['bootstrap_3_layout.html.twig']
))
    ->register(new Silex\Provider\ValidatorServiceProvider())
    ->register(new Silex\Provider\TranslationServiceProvider(), array(
        'translator.domains' => array(),
    ))
    ->register(
        new GeckoPackages\Silex\Services\Config\ConfigServiceProvider(),
        [
            'config.dir' => __DIR__.'/../config',
            'config.format' => '%key%.yml',
        ])
    ->register(new FormServiceProvider());


$app['blockchain'] = function () use($app){
    return new BlockChainService($app['config']['app']['server_url'], $app['monolog']);
};

$app->match('/', function (Request $request) use($app){

    /** @var \Symfony\Component\Form\Form $form */
    $form = $app['form.factory']->createBuilder(FormType::class, null, [
        'action' => '/',
            'validation_groups' => function (FormInterface $form) {
                $data = $form->getData();

                if($data['file'] !== null){
                    return ['File'];
                }
                elseif($data['hash'] !== null){
                    return ['Hash'];
                }

                return['File'];
            }
    ])
        ->add('hash', TextType::class,[
            'label' => 'Хеш файлу',
            'required' => false,
            'constraints' => [new NotBlank([
                'groups' => ['Hash']
            ])]
        ])
        ->add('file', FileType::class,[
            'label' => 'Файл витягу',
            'required' => false,
            'constraints' => [new File([
                'groups' => ['File'],
                'maxSize' => '10M'
            ])]
        ])
        ->add('submit', SubmitType::class,[
            'label' => 'Пошук',
            'attr' => [
                'class' => 'btn btn-primary',
            ]
        ])->getForm();

    $form->handleRequest($request);

    if($form->isSubmitted()){
        if ($form->isValid()) {
            $data = $form->getData();

            try{
                if($data['hash']){
                    $res = $app['blockchain']->getDataByHash($data['hash']);
                }
                elseif ($data['file']){
                    $res = $app['blockchain']->getDataByFile(file_get_contents($data['file']->getPathname()));
                }else{
                    throw new UnexpectedValueException();
                }

                // do something with the data

                // redirect somewhere
                return new JsonResponse($res);
            }catch (BlockChainRequestException $e ){
                return new JsonResponse(['msg' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }
            catch (\Exception $e){
                return new JsonResponse(['msg' => 'Виникла критична помилка.'], Response::HTTP_BAD_REQUEST);
            }
        }
    }

    return $app['twig']->render('index.html.twig', array(
        'form' => $form->createView()
    ));
});



class BlockChainService
{
    /** @var string  */
    private $hashAlgorithm = 'sha256';
    /** @var  string */
    private $setUrl = '/api/services/timestamping/v1/content';
    /** @var  string */
    private $getUrl = '/api/services/timestamping/v1/info/%s';
    /** @var  Client */
    private $client;
    /** @var  Logger */
    private $logger;

    public function __construct($serverUrl, Logger $logger)
    {
        $this->client = new Client(
            [
                'base_uri' => $serverUrl
            ]
        );

        $this->logger = $logger;
    }

    public function getDataByFile($data)
    {
        try{
            $res = $this->client->request('GET', sprintf($this->getUrl, hash($this->hashAlgorithm, base64_encode($data))));

            $res = json_decode($res->getBody(), true);

            $res['content']['description'] = json_decode($res['content']['description'], true);

            return $res;

        }catch (ClientException $e){

            $res = json_decode($e->getResponse()->getBody(), true);
            throw new BlockChainRequestException($this->getTranslation($res['type'], $e->getResponse()->getStatusCode()), $e->getResponse()->getStatusCode());
        }
        catch (RequestException $e){
            $this->logger->error($e);
            throw $e;
        }
    }

    public function getDataByHash($hash)
    {
        try{
            $res = $this->client->request('GET', sprintf($this->getUrl, $hash));

            $res = json_decode($res->getBody(), true);

            $res['content']['description'] = json_decode($res['content']['description'], true);

            return $res;

        }catch (ClientException $e){

            $res = json_decode($e->getResponse()->getBody(), true);
            throw new BlockChainRequestException($this->getTranslation($res['type'], $e->getResponse()->getStatusCode()), $e->getResponse()->getStatusCode());
        }
        catch (RequestException $e){
            $this->logger->error($e);
            throw $e;
        }
    }

    public function getTranslation($msg, $statusCode)
    {
        $translations = [
            'FromHex' => 'Некоректний запит',
            'FileExists' => 'Таке значення вже існує',
            'FileNotFound' => 'Файл не знайдено'
        ];

        if(array_key_exists($msg, $translations))
        {
            return $translations[$msg];
        }
        else{
            if($statusCode == Response::HTTP_NOT_FOUND && $msg == ''){
                return 'Запис не знайдено';
            }
        }

        return $msg;

    }
}

class BlockChainRequestException extends \Exception{}

$app->run();