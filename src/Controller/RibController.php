<?php

namespace App\Controller;

use DateTime;
use Doctrine\Common\Annotations\Annotation\Required;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mime\Header\UnstructuredHeader;

use function PHPUnit\Framework\throwException;

class RibController extends AbstractController
{
    /**
     * @Route("/", name="rib")
     */
    public function index(Request $request): Response
    {   
        $results = [];
        $errorMsg = ' ';

        $form = $this->createFormBuilder()
            ->add('rib', TextType::class, [
                'attr' => [
                'placeholder' => 'Veuillez rentrer les 10 chiffres qui compose votre RIB',
                ]
            ])
            ->add('startDate', DateType::class)
            ->add('endDate', DateType::class)
            ->add('rechercher', SubmitType::class)
            ->getForm();
            
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result    = $form->getData();
            $startDate = $result["startDate"]->getTimestamp();
            $endDate   = $result["endDate"]->getTimestamp();
            $searchRib = $result["rib"];

            if (preg_match( '/^[0-9]{21}+$/',$searchRib) || empty($searchRib)){
                $errorMsg = "Veuillez verifier le RIB que vous venez d'entré";
                return $this->render('rib/index.html.twig', [
                    'form'    => $form->createView(),
                    'results' => $results,
                    'messageError' => $errorMsg,
                ]);
            }

            $client  = HttpClient::create();
            $getRibs = $client->request('GET', 'http://localhost:3000/api/operations/'. $searchRib);
    
            $statusCode = $getRibs->getStatusCode();
            if (200 != $statusCode){
                $errorMsg = "Un probléme est survenu veuillez verifiez les information que vous rentré";
            }
            if (400 === $statusCode) {
                throw new BadRequestHttpException('Une erreur c\'est produit veuillez verifier le rib que vous avez rentré',null, 400);
            }
            //todo creat an expetion if status is not 200 with explicite msg
            $content = $getRibs->getContent();
            $content = $getRibs->toArray();
           
            $content = $this->ifDiff($content);
            
            $content = $this->sortByDate($content);
           
            $totalIncome = 0;
            $totalExpense = 0;

            foreach ($content as $rib) {
                $ribDate = strtotime($rib["date"]);
                if ($ribDate >= $startDate && $ribDate <= $endDate) {
                    $income = 0;
                    $expense = 0;
                    if ($rib["amount"] > 0 ) {
                        $income = $rib["amount"];
                    } else {
                        $expense = $rib["amount"];
                    }

                    $totalIncome += $income;
                    $totalExpense += $expense;
                  
                    $rib['income']  = $income;
                    $rib['expense'] = $expense;
                    $results[] = $rib;
                }
            }
            if(empty($results)) {
                $errorMsg = "Aucune operation trouver a cette periode là ou pour ce RIB";
            }
            
            return $this->render('rib/index.html.twig', [
                'form'         => $form->createView(),
                'results'      => $results,
                'totalExpense' => $totalExpense,
                'totalIncome'  => $totalIncome,
                'messageError' => $errorMsg,
            ]);
        };
        
        return $this->render('rib/index.html.twig', [
            'form'    => $form->createView(),
            'results' => $results,
            'messageError' => $errorMsg,
        ]);
    }

    public static function sortByDate($results) {
        usort($results, function($a, $b) {
            return new DateTime($a['date']) <=> new DateTime($b['date']);
         });
        return $results; 
    }

    public static function ifDiff($results) {
    $badOperation = 0;
       foreach ($results as $rib) {
           foreach ($results as $diffRib) {
                if ($rib["id"] != $diffRib["id"] && $rib["amount"] === $diffRib["amount"] && $rib["date"] === $diffRib["date"]) {
                    unset($results[$badOperation]);
                }
            }
            $badOperation ++;
        }
        return $results; 
    }
}
