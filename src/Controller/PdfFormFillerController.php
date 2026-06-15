<?php

declare(strict_types=1);

namespace Koboldsoft\PdfFormFillerBundle\Controller;

use Koboldsoft\PdfFormFillerBundle\Service\PdfFormFiller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PdfFormFillerController extends AbstractController
{
    private PdfFormFiller $pdfFormFiller;

    public function __construct(PdfFormFiller $pdfFormFiller)
    {
        $this->pdfFormFiller = $pdfFormFiller;
    }

    /**
     * @Route("/pdf-form-filler/vermittlung-mitteilung", name="pdf_form_filler_vermittlung_mitteilung", methods={"GET", "POST"})
     */
    public function vermittlungMitteilung(Request $request): Response
    {
        return $this->createPdfResponse($request, 'vermittlung_mitteilung');
    }

    /**
     * @Route("/pdf-form-filler/vermittlung-teilnahmebericht", name="pdf_form_filler_vermittlung_teilnahmebericht", methods={"GET", "POST"})
     */
    public function vermittlungTeilnahmebericht(Request $request): Response
    {
        return $this->createPdfResponse($request, 'vermittlung_teilnahmebericht');
    }

    /**
     * @Route("/pdf-form-filler/amdl-mitteilung", name="pdf_form_filler_amdl_mitteilung", methods={"GET", "POST"})
     */
    public function amdlMitteilung(Request $request): Response
    {
        return $this->createPdfResponse($request, 'amdl_mitteilung');
    }

    /**
     * @Route("/pdf-form-filler/jobcenter-teilnahmebericht", name="pdf_form_filler_jobcenter_teilnahmebericht", methods={"GET", "POST"})
     */
    public function jobcenterTeilnahmebericht(Request $request): Response
    {
        return $this->createPdfResponse($request, 'jobcenter_teilnahmebericht');
    }

    private function createPdfResponse(Request $request, string $pdfKey): Response
    {
        $auftragId = filter_var($request->get('id'), FILTER_VALIDATE_INT);
        if ($auftragId === false || $auftragId < 1) {
            return new Response('Ungueltige Auftrags-ID.', 400);
        }

        try {
            $result = $this->pdfFormFiller->fillPdf($pdfKey, $auftragId, $this->shouldCreateEditablePdf($request));
            $response = new BinaryFileResponse($result['path']);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'inline; filename="' . $result['filename'] . '"');
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Throwable $e) {
            return new Response('Fehler beim Erstellen der PDF: ' . $e->getMessage(), 500);
        }
    }

    private function shouldCreateEditablePdf(Request $request): bool
    {
        $userAgent = $request->headers->get('User-Agent', '');

        return stripos($userAgent, 'Firefox/') !== false;
    }
}
