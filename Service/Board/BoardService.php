<?php

namespace NTI\TicketBundle\Service\Board;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use http\Exception\RuntimeException;
use JMS\Serializer\SerializationContext;
use NTI\EmailBundle\Entity\Smtp;
use NTI\EmailBundle\Form\SmtpType;
use NTI\TicketBundle\Entity\Board\Board;
use NTI\TicketBundle\Entity\Board\BoardResource;
use NTI\TicketBundle\Event\TicketBoardCreatedEvent;
use NTI\TicketBundle\Exception\DatabaseException;
use NTI\TicketBundle\Exception\InvalidFormException;
use NTI\TicketBundle\Form\Board\BoardType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Form;

class BoardService
{

    const BOARD_BASE_SERIALIZATION = array("nti_ticket_board", "nti_ticket_board_resource");
    const BOARD_LIST_SERIALIZATION = "nti_ticket_board_list";

    /** @var EntityManagerInterface $em */
    private $em;
    private $container;
    private $APP_PATH_SPOOL;
    private $dispatcher;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->em = $container->get('doctrine')->getManager();
        $this->APP_PATH_SPOOL = $this->container->getParameter('app.path.spool');
        $this->dispatcher = $container->get('event_dispatcher');
    }

    /**
     * @param bool $serialize
     * @return array
     * @throws \NTI\TicketBundle\Exception\ProcessedBoardResourcesException
     */
    public function getAll($serialize = false){
        // all boards
        $boards = $this->em->getRepository(Board::class)->findBy(array(), array('name' => 'asc'));

        $response = array();

        // board resources
        /** @var Board $board */
        foreach ($boards as $board){
            $resources = $this->container->get('nti_ticket.resource.repository')->getResourcesByBoard($board);
            $eventResources = $this->container->get('nti_ticket.resource.repository')->getByUniqueIdCollection($board->getEventResources());
            $response[] = $this->processBoard($board, $resources, $eventResources, $serialize);
        }

        return $response;

    }

    public function getActiveListByResource(string $resourceId){
        $list =  $this->em->getRepository(Board::class)->getBoardsByResource($resourceId);
        return json_decode($this->container->get('jms_serializer')->serialize($list, 'json', SerializationContext::create()->setGroups($this::BOARD_LIST_SERIALIZATION)), true);
    }


    /**
     * @param $id
     * @param bool $serialized
     * @return mixed|Board|null|object
     * @throws \NTI\TicketBundle\Exception\ProcessedBoardResourcesException
     */
    public function getById($id, $serialized = false){
        /** @var Board $board */
        $board = $this->em->getRepository(Board::class)->find($id);
        if ($board) {
            $resources = $this->container->get('nti_ticket.resource.repository')->getResourcesByBoard($board);
            $eventResources = $this->container->get('nti_ticket.resource.repository')->getByUniqueIdCollection($board->getEventResources());
            $board = $this->processBoard($board, $resources, $eventResources, $serialized);
        }
        return $board;
    }

    /**
     * @param $uniqueId
     * @param bool $serialized
     * @return mixed|Board|null|object
     * @throws \NTI\TicketBundle\Exception\ProcessedBoardResourcesException
     */
    public function getByUniqueId($uniqueId, $serialized = false){
        /** @var Board $board */
        $board = $this->em->getRepository(Board::class)->findOneBy(array('uniqueId'=>$uniqueId));
        if ($board) {
            $resources = $this->container->get('nti_ticket.resource.repository')->getResourcesByBoard($board);
            $eventResources = $this->container->get('nti_ticket.resource.repository')->getByUniqueIdCollection($board->getEventResources());
            $board = $this->processBoard($board, $resources, $eventResources, $serialized);
        }
        return $board;
    }

    /**
     * @return null|Board
     */
    public function getLandingBoard(){
        return $this->em->getRepository(Board::class)->findOneBy(array('isLanding'=> true));
    }

    /**
     * @param Board $board
     * @param array $resources
     * @param array $eventResources
     * @param bool $serialized
     * @return mixed|Board
     */
    private function processBoard(Board $board, $resources = array(), $eventResources = array(), $serialized = false){
        if ($serialized) {
            $resources = json_decode($this->container->get('jms_serializer')->serialize($resources, 'json', SerializationContext::create()->setGroups($this::BOARD_BASE_SERIALIZATION)), true);
            $eventResources = json_decode($this->container->get('jms_serializer')->serialize($eventResources, 'json', SerializationContext::create()->setGroups($this::BOARD_BASE_SERIALIZATION)), true);
            $board = json_decode($this->container->get('jms_serializer')->serialize($board, 'json', SerializationContext::create()->setGroups($this::BOARD_BASE_SERIALIZATION)), true);
            $board['resources'] = $resources;
            $board['eventResources'] = $eventResources;
        }else{
            $boardResources = new ArrayCollection();
            foreach ($resources as $resource){
                if (!$boardResources->contains($resource))
                    $boardResources->add($resource);
            }
            $board->setResourcesManually($boardResources);
        }
        return $board;
    }

    /**
     * @param array $data
     * @param bool $serialized
     * @param string $formType
     * @return mixed|Board
     * @throws DatabaseException
     * @throws InvalidFormException
     * @throws \NTI\TicketBundle\Exception\ProcessedBoardResourcesException
     * @throws Exception
     */
    public function create($data = array(), $serialized = false, $formType = BoardType::class)
    {
        $board = new Board();

        # -- form validation
        /** @var Form $form */
        $form = $this->container->get('form.factory')->create($formType, $board);
        $form->submit($data);
        if (!$form->isValid()) throw new InvalidFormException($form);

        // board resources
        $this->handleBoardResources($board, $data);

        try {
            $this->em->persist($board);
            $this->em->flush();
        } catch (Exception $ex) {
            throw new DatabaseException();
        }

        // handling response
        $resources = $this->container->get('nti_ticket.resource.repository')->getResourcesByBoard($board);
        $eventResources = $this->container->get('nti_ticket.resource.repository')->getByUniqueIdCollection($board->getEventResources());

        /**
         * Dispatching the created Ticket Event
         */
        try {
            $event = new TicketBoardCreatedEvent($board);
            $result = $this->dispatcher->dispatch(TicketBoardCreatedEvent::TICKET_BOARD_CREATED, $event);
        }catch (\Exception $exception){
            // what ever that happened here we assume that you simply do not want to track notifications.
        }

        // Test Email Connector
        $connectionExecption = null;
        try {
            $this->testConnectionService($board);
            $board->setConnectionStatus(true);
        } catch (\Exception $exception) {
            $board->setConnectionStatus(false);
            $connectionExecption = $exception;
        }

        try {
            // Save board
            $this->em->flush();
        } catch (Exception $ex) {
            throw new DatabaseException();
        }
        if($connectionExecption) {
            throw $connectionExecption;
        }

        return $this->processBoard($board, $resources, $eventResources, $serialized);
    }


    /**
     * @param Board $board
     * @param array $data
     * @param bool $isPatch
     * @param bool $serialized
     * @param string $formType
     * @return Board
     * @throws DatabaseException
     * @throws InvalidFormException
     * @throws \NTI\TicketBundle\Exception\ProcessedBoardResourcesException
     * @throws Exception
     */
    public function update(Board $board, $data = array(), $isPatch = false, $serialized = false, $formType = BoardType::class){
        // Validate Password
        if(!array_key_exists("connectorPassword", $data)) {
            $data["connectorPassword"] = $board->getConnectorPassword();
        }
        if(array_key_exists("connectorPassword", $data) && ($data["connectorPassword"] === null || $data["connectorPassword"] === '')) {
            $data["connectorPassword"] = $board->getConnectorPassword();
        }
        # -- form validation
        /** @var Form $form */
        $form = $this->container->get('form.factory')->create($formType, $board);
        $form->submit($data, !$isPatch);
        if (!$form->isValid()) throw new InvalidFormException($form);

        // board resources
        $this->handleBoardResources($board, $data);

        // handling response
        $resources = $this->container->get('nti_ticket.resource.repository')->getResourcesByBoard($board);
        $eventResources = $this->container->get('nti_ticket.resource.repository')->getByUniqueIdCollection($board->getEventResources());

        try {
            // Save board
            $this->em->flush();
        } catch (Exception $ex) {
            throw new DatabaseException();
        }

        /**
         * Dispatching the created Ticket Event
         */
        try {
            $event = new TicketBoardCreatedEvent($board);
            $result = $this->dispatcher->dispatch(TicketBoardCreatedEvent::TICKET_BOARD_CREATED, $event);
        }catch (\Exception $exception){
            // what ever that happened here we assume that you simply do not want to track notifications.
        }

         // Test Email Connector
        $connectionExecption = null;
        try {
            $this->testConnectionService($board);
            $board->setConnectionStatus(true);
        } catch (\Exception $exception) {
            $board->setConnectionStatus(false);
            $connectionExecption = $exception;
        }

        try {
            // Save board
            $this->em->flush();
        } catch (Exception $ex) {
            throw new DatabaseException();
        }
        if($connectionExecption) {
            throw $connectionExecption;
        }
        return $this->processBoard($board, $resources, $eventResources, $serialized);
    }

    /**
     * parameters validation and connection test.
     * @throws Exception
     */
    public function testConnectionService(Board $board) {
        try{
            $this->container->get('nti_ticket.connector.exchange.service')->testConnection($board);
        } catch (\Exception $exception){
            throw $exception;
        }
    }

    /**
     * add or remove board resources given the comparative of the array of resources uniqueId.
     * @param Board $board
     * @param array $data
     */
    private function handleBoardResources(Board $board, $data = array())
    {
        # -- parsing data
        $resourcesData = (array_key_exists('resources', $data) && (!empty($data['resources']))) ? $data['resources'] : array();

        // groups id's keys only
        $filter = array_map(function ($value) {
            return $value['uniqueId'];
        }, $resourcesData);


        // new board resources handler
        if ($board->getId() == null && !empty($resourcesData) && !empty($filter)) {
            foreach ($filter as $uniqueId) {
                $resource = new BoardResource();
                $resource->setResource($uniqueId);
                $board->addResource($resource);
            }
        }

        // existing board resources handler
        if ($board->getId() != null){

            $current = array();
            foreach ($board->getResources() as $resource){
                $current[] = $resource->getResource();
            }

            // adding new
            if (($toAdd = array_diff($filter, $current))) {
                foreach ($toAdd as $uniqueId) {
                    $resource = new BoardResource();
                    $resource->setResource($uniqueId);
                    $board->addResource($resource);
                }
            }

            # removing unmarked
            if (($toRemove = array_diff($current, $filter))) {
                $resources = $this->em->getRepository(BoardResource::class)->getMultipleByBoardAndUniqueIdCollection($board, $toRemove);
                foreach ($resources as $resource) {
                    $this->em->remove($resource);
                }
            }
        }
    }
}