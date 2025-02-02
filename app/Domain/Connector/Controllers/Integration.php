<?php

namespace Leantime\Domain\Connector\Controllers {

    use Leantime\Core\Controller;
    use Leantime\Core\Frontcontroller;
    use Leantime\Domain\Auth\Models\Roles;
    use Leantime\Domain\Canvas\Repositories\Canvas;
    use Leantime\Domain\Connector\Services\Connector;
    use Leantime\Domain\Connector\Services\Providers;
    use Leantime\Domain\Connector\Services\Integrations as IntegrationService;
    use Leantime\Domain\Connector\Models\Integration as IntegrationModel;
    use Leantime\Domain\Connector\Repositories\LeantimeEntities;
    use Leantime\Domain\Auth\Services\Auth;
    use Leantime\Domain\Goalcanvas\Repositories\Goalcanvas;
    use Leantime\Domain\Ideas\Repositories\Ideas;
    use Leantime\Domain\Projects\Services\Projects;
    use Leantime\Domain\Tickets\Services\Tickets;
    use Leantime\Domain\Users\Services\Users;

    /**
     *
     */
    class Integration extends Controller
    {
        private Providers $providerService;
        private IntegrationService $integrationService;
        private LeantimeEntities $leantimeEntities;

        private Users $userService;
        private Tickets $ticketService;

        private Projects $projectService;

        private array $values = array();
        private array $fields = array();
        private Ideas $ideaRepository;
        private Canvas $canvasRepository;
        private Goalcanvas $goalRepository;

        private Connector $connectorService;

        /**
         * constructor - initialize private variables
         *
         * @access public
         *
         */
        public function init(
            Providers $providerService,
            IntegrationService $integrationService,
            LeantimeEntities $leantimeEntities,
            Users $userService,
            Tickets $ticketService,
            Projects $projectService,
            Ideas $ideaRepository,
            Goalcanvas $goalRepository,
            Canvas $canvasRepository,
            Connector $connectorService
        ) {
            Auth::authOrRedirect([Roles::$owner, Roles::$admin]);

            $this->providerService = $providerService;
            $this->leantimeEntities = $leantimeEntities;
            $this->integrationService = $integrationService;
            $this->userService = $userService;
            $this->ticketService = $ticketService;
            $this->projectService = $projectService;
            $this->ideaRepository = $ideaRepository;
            $this->goalRepository = $goalRepository;
            $this->canvasRepository = $canvasRepository;
            $this->connectorService = $connectorService;
        }


        /**
         * run - handle post
         *
         * @access public
         *
         */
        public function run()
        {

            $params = $_REQUEST;
            if(!isset($_SESSION['currentImportEntity'])){
                $_SESSION['currentImportEntity'] = '';
            }

            if (isset($params["provider"])) {


                //New integration with provider
                //Get the provider
                $provider = $this->providerService->getProvider($params["provider"]);
                $this->tpl->assign("provider", $provider);

                $currentIntegration = app()->make(IntegrationModel::class);

                if (isset($params["integrationId"])) {
                    $currentIntegration = $this->integrationService->get($params["integrationId"]);
                    $this->tpl->assign("integrationId", $currentIntegration->id);
                }


                /* Steps + + + + + + + + + + + + + + + + + + + + + + + */

                //STEP 0: No Step defined, new integration
                if (!isset($params["step"])) {
                    $this->tpl->display('connector.newIntegration');
                    return;
                }

                //STEP 1: Initiate connection
                if (isset($params["step"])  && $params["step"] == "connect") {
                    //This should handle connection UI
                    $provider->connect();
                }


                //STEP 2: Choose Entities to sync
                if (isset($params["step"]) && $params["step"] == "entity") {
                    $this->tpl->assign("providerEntities", $provider->getEntities());
                    $this->tpl->assign("leantimeEntities", $this->leantimeEntities->availableLeantimeEntities);

                    $this->tpl->display('connector.integrationEntity');
                    return;
                }

                //STEP 3: Choose Entities to sync
                if (isset($params["step"]) && $params["step"] == "fields") {

                    if(isset($_POST['leantimeEntities'])){
                        $entity = $_POST['leantimeEntities'];
                        $_SESSION['currentImportEntity'] = $entity;
                    }else if(isset($_SESSION['currentImportEntity']) && $_SESSION['currentImportEntity'] != "") {
                        $entity = $_SESSION['currentImportEntity'];
                    }else{
                        $this->tpl->setNotification("Entity not set", "error");

                        Frontcontroller::redirect(BASE_URL . "/connector/integration?provider=" . $provider->id . "");
                    }

                    $currentIntegration->entity = $entity;

                    $flags = $this->connectorService->getEntityFlags($entity);

                    $this->integrationService->patch($currentIntegration->id, array("entity" => $entity));

                    if (isset($currentIntegration->fields) && $currentIntegration->fields != '') {
                        $this->tpl->assign("providerFields", explode(",", $currentIntegration->fields));
                    } else {
                        $this->tpl->assign("providerFields", $provider->getFields());
                    }
                    $this->tpl->assign("flags", $flags);
                    $this->tpl->assign("leantimeFields", $this->leantimeEntities->availableLeantimeEntities[$entity]['fields']);
                    $this->tpl->display('connector.integrationFields');

                    return;
                }

                //STEP 4: Choose Entities to sync
                if (isset($params["step"]) && $params["step"] == "sync") {
                    //TODO UI to show sync schedule/options

                    $this->tpl->display('connector.integrationSync');
                    return;
                }

                //STEP 5: import Review
                if (isset($params["step"]) && $params["step"] == "parse") {

                    $this->values = $provider->geValues();

                    //Fetching the field matching and putting it in an array
                    $this->fields = array();
                    $this->fields = $this->connectorService->getFieldMappings($_POST);

                    $flags = array();
                    $flags = $this->connectorService->parseValues($this->fields, $this->values, $_SESSION['currentImportEntity'] );

                    //show the imported data as confirmation
                    $this->tpl->assign("values", $this->values);
                    $this->tpl->assign("fields", $this->fields);
                    $this->tpl->assign("flags", $flags);

                    $this->tpl->display('connector.integrationImport');
                    return;
                }

                //STEP 6: Do the import
                if (isset($params["step"]) && $params["step"] == "import") {

                    //Store data in DB
                    $values = unserialize($_SESSION['serValues']);
                    $fields = unserialize($_SESSION['serFields']);

                    $this->connectorService->importValues($fields, $values, $_SESSION['currentImportEntity']);

                    //display stored successfully message
                    $this->tpl->display('connector.integrationConfirm');
                }


            }
        }
    }
}
