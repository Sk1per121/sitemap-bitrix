<?
$_SERVER['DOCUMENT_ROOT'] = "/srv/www/complexbar.ru/htdocs";
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule("iblock");

$ILIST_ID = 43; // ID инфоблока со списком инфоблоков для карты сайта
$IBLACK_URL = 42; // ID инфоблока со списком исключений из карты сайта
$IADD_URL = 41; // ID инфоблока со списком дополнительных ссылок в карту сайта

filterURL($ILIST_ID, $IADD_URL, $IBLACK_URL, 10000);

function filterURL($ILIST_ID, $IADD_URL, $IBLACK_URL, $countUrlToFile) {

    deleteFiles('/', 'sitemap*.xml');

    $arURL = [];

    $listIBLOCK_ID = getElemSitemap($ILIST_ID);
    $listURL = getElemSitemap($IADD_URL);
    $blackList = getElemSitemap($IBLACK_URL);

    foreach ($listIBLOCK_ID as $IBLOCK_ID) {
        $SECTION_LIST = getSections($IBLOCK_ID);
        $SECTION_ACTIVE_LIST = getListActiveSection($SECTION_LIST);

        $SECTION_URL_LIST = $SECTION_ACTIVE_LIST["URL"];
        $ELEMENT_URL_LIST = getElementsURL($IBLOCK_ID, $SECTION_ACTIVE_LIST["ID"], false);

        $arSectElem = array_merge($SECTION_URL_LIST, $ELEMENT_URL_LIST);

        $countURL = count($arSectElem);

        if($arSectElem) {
            if($countURL <= $countUrlToFile) {
                $arURL['iblock' . $IBLOCK_ID] = $arSectElem;
            } else {
                for($i = 0; $i<ceil($countURL/$countUrlToFile); $i++) {
                    $arURL['iblock' . $IBLOCK_ID . '-' . ($i+1)] = array_slice($arSectElem, $i*$countUrlToFile, $countUrlToFile);
                }
            }
        }
    }

    $arURL["add"] = ["/"];
    if(!empty($listURL)) $arURL["add"] = array_merge($arURL["add"], $listURL);

    if(!empty($arURL)) {
        if(!empty($blackList)) {
            foreach ($arURL as $key => $val) {
                $arURL[$key] = array_filter($val, function ($item) use ($blackList) {
                    return !in_array($item, $blackList);
                });
            }
        }

        getXML($arURL);
    }
}

function deleteFiles($pathToFiles, $mask) {
    array_map('unlink', glob($_SERVER['DOCUMENT_ROOT'] . $pathToFiles . $mask));
}

function createXML($URL) {
    $urlSite = "https://www.complexbar.ru";
    $DATE = date(DateTime::W3C);
    $URL = $URL == "/" ? "" : $URL;
    $XML  = '<url>
             <loc>'.$urlSite.$URL.'</loc>
             <lastmod>'.$DATE.'</lastmod>
             </url>';

    return $XML;
}

function getXML($arURL) {
    $dir = "/";
    $doc_root = $_SERVER['DOCUMENT_ROOT'] . $dir;

    $XML_OPEN = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $XML_CLOSE = '</urlset>';

    $XML_MAP_FILES = $XML_OPEN;
    foreach ($arURL as $key => $arrURL) {
        $file_name = 'sitemap-' . $key . '.xml';
        $XML_MAP_FILES .= createXML($dir . $file_name);

        $XML = $XML_OPEN;
        foreach($arrURL as $item) {
            $XML .= createXML($item);
        }
        $XML .= $XML_CLOSE;

        file_put_contents($doc_root . $file_name, $XML);
    }
    $XML_MAP_FILES .= $XML_CLOSE;

    file_put_contents($doc_root . 'sitemap.xml', $XML_MAP_FILES);
}

function getElemSitemap($IBLOCK_ID) {
    $ADD_URL = [];
    $arrElem = CIBlockElement::GetList([], ["IBLOCK_ID"=>$IBLOCK_ID, "ACTIVE"=>"Y"], false, false, ["ID", "NAME", "ACTIVE"]);

    while($ar_res = $arrElem->GetNextElement()) {
        $ADD_URL[] = $ar_res->GetFields()["NAME"];
    }

    return $ADD_URL;
}

function getSections($IBLOCK_ID) {
    $SECTIONS = [];

    $arrSection = CIBlockSection::GetList(["left_margin"=>"asc"], ["IBLOCK_ID"=>$IBLOCK_ID], false, ["ID", "LEFT_MARGIN", "RIGHT_MARGIN", "DEPTH_LEVEL", "ACTIVE", "SECTION_PAGE_URL"], false);

    while($ar_res = $arrSection->GetNextElement()) {
        $arFields = $ar_res->GetFields();

        $SECTIONS[] = $arFields;
    }
    return $SECTIONS;
}

function getElementsURL($IBLOCK_ID, $arActiveSection, $count) {
    $URL = [];

    if(!$arActiveSection) {
        $arActiveSection = false;
    }

    $arSelect = ["ID", "DETAIL_PAGE_URL"];
    $arFilter = ["IBLOCK_ID"=>$IBLOCK_ID, "SECTION_ID"=>$arActiveSection, "IBLOCK_SECTION_ID"=>$arActiveSection, "ACTIVE"=>"Y"];
    $arrElem = CIBlockElement::GetList([], $arFilter, false, false, $arSelect);

    while($ar_res = $arrElem->GetNextElement()) {
        $res = $ar_res->GetFields()["DETAIL_PAGE_URL"];

        if(!empty($res)) {
            $URL[] = $ar_res->GetFields()["DETAIL_PAGE_URL"];
        }

        if($count !== false) {
            if($count <= 0) {
                break;
            }
            $count--;
        }
    }

    return $URL;
}

//Сортировка секций по левой границе ("left_margin"=>"asc") обязательна!
function getListActiveSection($sections) {
    $arActiveSection = ["ID"=>[], "URL"=>[]];

    $depthLevel = [];
    $active = true;
    $depthInactive = 0;
    $iter = 1;

    //Проходимся по всем элементам
    foreach ($sections as $itemSection) {
        //Если имеется подкатегория
        if($itemSection["RIGHT_MARGIN"] - $itemSection["LEFT_MARGIN"] > 1) {
            //Добавляем правую границу категории в массив
            array_push($depthLevel, $itemSection["RIGHT_MARGIN"]);

            //Проверяем, если текущая правая граница больше чем граница неактивной категории, т.к. находится на одном и том же уровне вложенности или выше и при этом категория неактивна
            if($itemSection["RIGHT_MARGIN"] > $depthInactive && $itemSection["ACTIVE"] != "Y") {
                $active = false;
                $depthInactive = $itemSection["RIGHT_MARGIN"];
                //Если родительские категории активны, текущая категория активна и имеется ссылка, добавляем URL
            } elseif ($active && $itemSection["ACTIVE"] === "Y" && !empty($itemSection["SECTION_PAGE_URL"])) {
                array_push($arActiveSection["ID"], $itemSection["ID"]);
                array_push($arActiveSection["URL"], $itemSection["SECTION_PAGE_URL"]);
            }
            //Если ее нет
        } else {
            //Если родительские категории активны, текущая категория активна и имеется ссылка, добавляем URL
            if($active && $itemSection["ACTIVE"] === "Y" && !empty($itemSection["SECTION_PAGE_URL"])) {
                array_push($arActiveSection["ID"], $itemSection["ID"]);
                array_push($arActiveSection["URL"], $itemSection["SECTION_PAGE_URL"]);
            }
            //Выходим из подкатегории. Если текущая подкатегория родителя является последней, (берем крайнюю границу родителя и сравниваем с текущей подкатегорией + 1, единицу прибавляем т.к. крайняя граница родителя всегда на единицу больше границы последней подкатегории), то выходим из нее. Цикл нужен для того, чтобы выйти из нескольких подкатегорий подряд.
            while(true) {
                if (end($depthLevel) == $itemSection["RIGHT_MARGIN"] + $iter) {
                    array_pop($depthLevel);
                    //Проверяем, границу неактивной категории и границу текущей, для того, чтобы понять, вышли мы из неактивной категории или нет
                    if ($depthInactive == $itemSection["RIGHT_MARGIN"] + $iter) {
                        $active = true;
                    }
                } else {
                    $iter = 1;
                    break;
                }
                $iter++;
            }
        }
    }

    return $arActiveSection;
}
