<?php

use Cocur\Slugify\Slugify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JMS\Serializer\SerializerBuilder;
use MyNamespace\MyObject;
use Orchestra\Parser\Xml\Facade as XmlParser;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
/*
Route::get('/importFromOriginalSite', function () {

    //testing destiny connection
    $portfolios = DB::connection('mysql3')->table('wp_posts')->where('post_type', 'dt_portfolios')->where('post_status', 'publish')->get();
    dd(123, $portfolios);
    
    //obtengo secciones info
    $expression = DB::select(DB::raw('SELECT DISTINCT SQL_CALC_FOUND_ROWS s.Indice AS idpadre, 
        a.id,a.seccion,a.idarchivo,a.tipo,a.nombre,a.nota,a.idseccion,a.seccion_id,ff.anio,s.Nombre 
        FROM archivos a 
        INNER JOIN secciones_secciones s ON (a.estado = 1 AND s.Estado = 1 AND s.Indice != 0 AND s.idIdioma = 1 AND a.idseccion = s.idSeccion) 
        INNER JOIN (SELECT tb.idTrabajo AS seccion_id, Anio AS anio FROM trabajos_trabajos AS tb WHERE tb.Estado = 1 ORDER BY seccion_id) as ff 
        ON (a.estado = 1 AND ff.seccion_id = a.seccion_id) WHERE a.Estado = 1 AND idtipo = 13 ORDER BY idarchivo DESC'));
    $seccionesInfo = [];
    foreach ($expression as $row) {
        $seccionesInfo[$row->Nombre][] = $row->idarchivo;
    }
    
    //obtengo por cada archivo su idTrabajo y nombre
    $totalInfo = [];
    foreach ($seccionesInfo as $s => $seccion){
        foreach ($seccion as $archivo){
            $resultArchivos = DB::table('trabajos_trabajos_archivos')->selectRaw('idArchivo, idTrabajo, Nombre')->where('idArchivo', $archivo)->where('idExtension', 13)->get();
            if (count($resultArchivos) == 1){
                $totalInfo[$s][$archivo] = [
                    'idArchivo' => $archivo,
                    'idtrabajo' => $resultArchivos[0]->idTrabajo,
                    'nombreArchivo' => $resultArchivos[0]->Nombre,
                ];
                $query = "idTrabajo,idIdioma,NumeroRegistro,Serie,Clase,Titulo,Lugar,Anio,FechaInicio,FechaFin,Dimensiones,Tecnica,Firma,Ediciones,Observaciones";
                $resultInfo = DB::table('trabajos_trabajos')->selectRaw($query)->where('idTrabajo', $resultArchivos[0]->idTrabajo)->where('idIdioma', 1)->where('Estado', 1)->first();
                if ($resultInfo != null){
                    $totalInfo[$s][$archivo]['info'] = $resultInfo;
                } 
            } 
        }
    }

    //obtengo archivos copiados en public
    $files = Storage::allFiles('public');

    //recorro la info de cada archivo y agrego clave newtitle con (seccion - titlo - idarchivo ) slugueado y guardo en $floder
    //recorro los archivos copiados y los renombro en carpetas segun portfolio y usando el campo newtitle
    foreach ($totalInfo as $s => $seccion){
        foreach ($seccion as $file){
            $slugify = new Slugify();
            if (isset($file['info'])){
                if ($s == "Obras realizadas"){
                    $sec = 'o';
                } else {
                    $sec = $s;
                }
                $file['info']->newtitle = $slugify->slugify($sec) . '__' . $slugify->slugify($file['info']->Titulo) . '_' . $file['idArchivo'];
                //evito duplicacion de archivos
                if (!in_array('public/archivos/' . $sec . '/' . $file['info']->newtitle . '.jpg', $files)){
                    if (in_array('public/archivos/' . $file['idArchivo'] . '.jpg', $files)){
                        Storage::move('public/archivos/' . $file['idArchivo'] . '.jpg', 'public/archivos/' . $s . '/' . $file['info']->newtitle . '.jpg');
                    }
                }
            }
        }
    }

    //conexion db destino
    //obtengo los portfolios y les recorro su metavalue con los items, por cada uno encuentro su info e inserto su metadata a cada post imagen
    $portfolios = DB::connection('mysql3')->table('wp_posts')->where('post_type', 'dt_portfolios')->where('post_status', 'publish')->get();

    foreach ($portfolios as $portfolio){
        $metaportfolio = DB::connection('mysql3')->table('wp_postmeta')->where('post_id', $portfolio->ID)->where('meta_key', '_portfolio_settings')->first();
        $metavalue = unserialize($metaportfolio->meta_value);
        $portfolioFotos = $metavalue['items'];
        
        foreach ($portfolioFotos as $foto){
            $array = explode('/', $foto);
            $last = count($array);
            $fotoName = $array[$last-1];
            $sec = explode('__', $fotoName);
            $portfolio = ($sec[0] != 'dibujos')? ucfirst($sec[0]): $sec[0];
            $originalId = explode('_', $fotoName);
            $id = substr($originalId[count($originalId)-1], 0, -4);
            if (isset($totalInfo[$portfolio][$id])){
                $fileInfo = $totalInfo[$portfolio][$id]['info'];
                $imagePost = DB::connection('mysql3')->table('wp_posts')->where('guid', $foto)->first();
            
                //insert the metadata
                foreach ($fileInfo as $key => $field){
                    if ($key != 'newtitle' && $key != 'idTrabajo' && $key != 'idIdioma' && $key != 'Anio'){
                        if ($field != null){
                            switch($key) {
                                case('NumeroRegistro'):
                                    $k = 'numero_registro';
                                    break;
                                case('FechaInicio'):
                                    $k = 'fecha_inicio';
                                    break;
                                case('FechaFin'):
                                    $k = 'fecha_fin';
                                    break;
                                case('Titulo'):
                                    $k = 'nombre';
                                    break;
                                default:
                                    $k = strtolower($key);
                                    break;
                            }
                            //evito insertar duplicados
                            $exists = DB::connection('mysql3')->table('wp_postmeta')->where('post_id', $imagePost->ID)->where('meta_key', $k)->where('meta_value', $field)->first();
                            if (!$exists){
                                $insert = DB::connection('mysql3')->table('wp_postmeta')->insert([
                                    'post_id' => $imagePost->ID,
                                    'meta_key' => $k,
                                    'meta_value' => $field
                                ]);
                                Log::info('insert', ['post_id' => $imagePost->ID, 'meta_key' => $k, 'meta_value' => $field, 'result' => $insert]);
                            }
                        }
                    }
                }     
            }   
        }
    }

    return view('welcome');
});*/

Route::get('/newImportprocess', function () {
    //dd('149');
    //post_name = post_id (para encontrar el id de la que sera la feature image de cada uno)
    $thumbnails_ids = [
        //0
         'dibujos__casa-indihar_1920' => 10372,
         'dibujos__dibujos-1969_1720' => 10373,
         //1
         "o__casa-en-abril_969" => 10944,
         "o__rutalsur_977" => 10938,
         "o__casa-en-bella-vista_968" => 10945,
         "o__casa-en-jose-ignacio_957" => 10952,
         "o__el-reposo_947" => 10961,
         "o__casa-en-martindale-country-club_941" => 10967,
         "o__los-abrojos_933" => 10975,
         "o__konstandt-m_927" => 10981,
         "o__casa-en-las-condes_921" => 10987,
         "o__casa-en-club-sociedad-hebraica-argentina_915" => 10993,
         "o__casa-en-newman-country-club_909" => 10999,
         "o__la-calderona_903" => 11005,
         "o__casa-en-talar-de-pacheco_891" => 11017,
         "o__casa-en-la-horqueta_885" => 11023,
         "o__casa-en-pilar_879" => 11029,
         "o__casa-en-san-isidro_873" => 11035,
         "o__casa-en-la-horqueta-i_866" => 11041,
         "o__casa-en-la-isla-nordelta_860" => 11047,
         "o__casa-en-colonia_853" => 11053,
         "o__malabrigo_842" => 11059,
         "o__casa-en-la-pedrera_1928" => 11067,
         "o__casa-indihar_1892" => 11074,
         "o__casa-en-san-jorge-village_1882" => 11083,
         "o__casa-en-laguna-del-sol_1066" => 11101,
         "o__virtuani_1060" => 11107,
         "o__casa-en-tortugas-country-club_1029" => 11125,
         //2
         "proyectos__abelleyra_978" => 10806,
         'proyectos__casa-en-abril_1699' => 10846,
         'proyectos__casa-en-bella-vista_1717' => 10828,
         'proyectos__casa-en-cardon-miramar-links_1736' => 10823,
         'proyectos__casa-en-club-sociedad-hebraica-argentina_1700' => 10845,
         'proyectos__casa-en-cumelen-country-club_845' => 10809,
         'proyectos__casa-en-jose-ignacio_1716' => 10829,
         'proyectos__casa-en-la-horqueta_1003' => 10862,
         'proyectos__casa-en-la-isla-nordelta_1697' => 10848,
         'proyectos__casa-en-las-condes_1712' => 10833,
         'proyectos__casa-en-martindale-country-club_1713' => 10832,
         'proyectos__casa-en-newman-country-club_1698' => 10847,
         'proyectos__casa-en-olivos_986' => 10803,
         'proyectos__casa-en-pacheco_1014' => 10854,
         'proyectos__casa-en-pilar_1692' => 10850,
         'proyectos__casa-en-san-jorge_1707' => 10838,
         'proyectos__casa-en-tigre_1008' => 10859,
         'proyectos__casa-en-tortugas-country-club_1691' => 10851,
         'proyectos__el-reposo_1730' => 10826,
         'proyectos__la-calderona_1721' => 10827,
         'proyectos__proyecto-casa-alhadeff_1929' => 10811,
         'proyectos__tajamares-de-la-pedrera_1696' => 10849,
         //3
         'interiores__casa-en-bella-vista_1662' => 10425,
         'interiores__casa-en-club-sociedad-hebraica-argentina_1663' => 10424,
         'interiores__casa-en-el-talar-de-pacheco_1682' => 10407,
         'interiores__casa-en-jose-ignacio_1624' => 10463,
         'interiores__casa-en-la-celina_1678' => 10411,
         'interiores__casa-en-las-condes_1654' => 10433,
         'interiores__casa-en-martindale-country-club_1623' => 10464,
         'interiores__casa-en-nordelta_1687' => 10402,
         'interiores__casa-en-san-isidro_1643' => 10444,
         'interiores__la-calderona_1639' => 10448,
         'interiores__malabrigo_1616' => 10471,
         'interiores__r0062-e70_1688' => 10401,
         'interiores__r0120-e72_1689' => 10400,
         'interiores__santiago-del-estero_1601' => 10486,
         'sony-dsc-25' => 10484,
        //4 equipamientos
         'equipamiento__cama_1222' => 10394,
         'equipamiento__candelabro_1218' => 10395,
         'equipamiento__mesa-comedor_1227' => 10390,
         'equipamiento__mesa-living_1228' => 10389,
         'equipamiento__silla-en-metal_1224' => 10393,
         'equipamiento__lampara_1215' => 10398,
         'equipamiento__silla_1213' => 10399,
        //5
         "otros__natura-aurea_1186" => 10380,
         "otros__piggy-pet_1235" => 10383,
         "otros__concurso-telon-para-el-teatro-colon_1690" => 10382,
        //6
        'dibujos__adios-completo-ramon-oliveira-cezar-h_723' => 11135,
        'dibujos__amarras_1165' => 11203,
        'dibujos__auto-de-fe_162' => 11189,
        'dibujos__carancho-del-monte_365' => 11142,
        'dibujos__ciudad-imaginaria_325' => 11149,
        'dibujos__ciudadela-rivero_225' => 11180,
        'dibujos__el-pais-de-plata_224' => 11181,
        'dibujos__el-poder_332' => 11146,
        'dibujos__fragmento-del-inexplicable-penasco-de-prometeo_364' => 11143,
        'dibujos__hipotesis-para-el-incendio-de-un-cubo_149' => 11200,
        'dibujos__hipotesis-para-la-destruccion-de-una-fabrica_117' => 11202,
        'dibujos__la-esfera_273' => 11172,
        'dibujos__la-gloria_333' => 11145,
        'dibujos__la-mar-oceana_733' => 11132,
        'dibujos__la-rosa-blindada_331' => 11147,
        'dibujos__las-ciudades-de-plata_1695' => 11182,
        'dibujos__las-montanas-de-plata_234' => 11178,
        'dibujos__las-prisiones_155' => 11194,
        'dibujos__libro-el-aire-todo-rene-bedel-imprenta-colombo-buenos-air_1125' => 11207,
        'dibujos__libro-el-aire-todo-rene-bedel-imprenta-colombo-buenos-ai_1127' => 11205,
        'dibujos__logos_330' => 11148,
        'dibujos__los-horizontes-de-plata_296' => 11154,
        'dibujos__vivienda-economica_1203' => 11201,
        //7
        'esculturas__acropolis_542' => 11619,
        'esculturas__aequatio_1195' => 11754,
        'esculturas__al-acero_216' => 11692,
        'esculturas__alter-ego_1101' => 11810,
        'esculturas__anillo-brazalete-y-diadema_470' => 11631,
        'esculturas__antimateria_1136' => 11783,
        'esculturas__aproximacion-a-la-incertidumbre_1735' => 11708,
        'esculturas__aproximacion-al-infinito_1175' => 11761,
        'esculturas__aproximacion-al-infinito-la-gran-muralla_1173' => 11759,
        'esculturas__argos_1134' => 11785,
        'esculturas__caja-de-reflexionii_1164' => 11769,
        'esculturas__caracu_359' => 11673,
        'esculturas__ciudad-para-armar_1107' => 11804,
        'esculturas__constituyentes-de-santa-fe_352' => 11678,
        'esculturas__cromosombra_1075' => 11833,
        'esculturas__culebron-hembra_428' => 11652,
        'esculturas__culebron-macho_427' => 11653,
        'esculturas__das-schloss_356' => 11675,
        'esculturas__dura-lex-sed-lex_1909' => 11696,
        'esculturas__el-agua-herida_546' => 11618,
        'esculturas__el-arquitecto-de-america_1476' => 11722,
        'esculturas__el-basilisco_1734' => 11709,
        'esculturas__el-dios-desconocido_391' => 11660,
        'esculturas__el-dios-reencontrado_387' => 11662,
        'esculturas__el-dueno-del-mundo_467' => 11634,
        'esculturas__el-entrevero_1860' => 11700,
        'esculturas__el-espejo_1129' => 11788,
        'esculturas__el-gran-limite_218' => 11691,
        'esculturas__el-huevo-y-la-serpiente_533' => 11620,
        'esculturas__el-improbable-romance-entre-el-plano-y-la-esfera_1907' => 11698,
        'esculturas__el-manantial_529' => 11623,
        'esculturas__el-ojo-de-dios_1109' => 11802,
        'esculturas__el-rayo-que-no-cesa_1196' => 11753,
        'esculturas__el-tunel_1168' => 11766,
        'esculturas__el-zahir_740' => 11613,
        'esculturas__el-zonda_478' => 11629,
        'esculturas__energeia_1167' => 11767,
        'esculturas__eros-y-tanatos_186' => 11701,
        'esculturas__espejismo_1122' => 11791,
        'esculturas__fragmento-del-inexplicable-penasco-de-prometeo_357' => 11674,
        'esculturas__hipotesis-para-el-colapso-de-un-edificio_373' => 11669,
        'esculturas__hipotesis-para-la-definicion-de-una-sombra_1207' => 11744,
        'esculturas__hipotesis-para-la-desaparicion-de-una-piedra_1176' => 11758,
        'esculturas__hipotesis-para-la-destruccion-de-un-cubo_231' => 11686,
        'esculturas__hipotesis-para-la-destruccion-del-puente-avellaneda_1197' => 11752,
        'esculturas__hipotesis-para-la-destruccion-del-puente-de-l-anglois_1194' => 11755,
        'esculturas__hipotesis-para-una-prision_1172' => 11762,
        'esculturas__jeronimo_176' => 11707,
        'esculturas__la-cabeza-de-ramirez_172' => 11710,
        'esculturas__la-comida-de-los-argentinos_1479' => 11599,
        'esculturas__la-letra-silenciosa_741' => 11612,
        'esculturas__la-mano-de-dios_1457' => 11726,
        'esculturas__la-sabiduria_179' => 11702,
        'esculturas__la-sentencia_131' => 11737,
        'esculturas__la-siesta-del-general-gueemes_171' => 11711,
        'esculturas__la-soledad_178' => 11703,
        'esculturas__la-vuelta-al-hogar_123' => 11742,
        'esculturas__labyrinthos_370' => 11672,
        'esculturas__las-llaves-del-reino_532' => 11621,
        'esculturas__las-manifestaciones-de-septiembre_1201' => 11749,
        'esculturas__los-desastres-de-la-guerra_138' => 11734,
        'esculturas__los-due-os-del-mundo_1480' => 11720,
        'esculturas__los-duenos-del-mundo_448' => 11649,
        'esculturas__los-espacios-vitales_169' => 11713,
        'esculturas__los-principes-australes_344' => 11680,
        'esculturas__mamacuna_1236' => 11741,
        'esculturas__maqueta-concurso-argencard_385' => 11663,
        'esculturas__mercator_372' => 11670,
        'esculturas__microcosmos_1138' => 11781,
        'esculturas__moderator_1908' => 11697,
        'esculturas__monumento-a-los-caidos-en-las-malvinas_400' => 11656,
        'esculturas__monumento-al-gral-mosconi_324' => 11681,
        'esculturas__monumento-al-iv-centenario-de-la-fundacion-de-la-ciudad-de-b_323' => 11682,
        'esculturas__monumento-al-prisionero-politico_140' => 11732,
        'esculturas__natura-aurea_1180' => 11606,
        'esculturas__nicolas_177' => 11706,
        'esculturas__obelisco-comercial-del-plata_445' => 11650,
        'esculturas__obelisco-juri_390' => 11661,
        'esculturas__obelisco-punta-del-este_1482' => 11718,
        'esculturas__objeto-paradojal_1130' => 11787,
        'esculturas__opforma_1087' => 11821,
        'esculturas__premio-alto-palermo_401' => 11655,
        'esculturas__premio-banco-republica_477' => 11630,
        'esculturas__premio-emilio-poblet_1486' => 11716,
        'esculturas__premio-hoechst_403' => 11654,
        'esculturas__res-vitae_1774' => 11705,
        'esculturas__scientia-premio-inet_517' => 11624,
        'esculturas__tensiones_1198' => 11751,
        'esculturas__thalassa_1901' => 11699,
        'esculturas__trofeo-fondo-nacional-de-las-artes_434' => 11651,
        'esculturas__victoria_468' => 11633,
        'esculturas__vivienda-economica-modelo-super-compacto-para-armar_1202' => 11748,
        //8
        "grabados__auto-de-fe_175" => 10897,
        "grabados__concentracion-de-la-imagen-en-un-punto_1178" => 10930,
        "grabados__desarrollo-de-perspectiva-en-un-punto-acueducto-del-gard_1188" => 10929,
        "grabados__el-panteon-de-agrippa-roma_1832" => 10885,
        "grabados__el-partenon-atenas_1824" => 10893,
        "grabados__espacios-imaginarios_1189" => 10928,
        "grabados__estudio-para-la-reconstruccion-de-un-papel-de-dibujo_1192" => 10924,
        "grabados__hipotesis-para-el-bombardeo-de-la-basilica-de-san-pedro_129" => 10910,
        "grabados__hipotesis-para-el-desarrollo-de-una-mancha-de-humedad_128" => 10911,
        "grabados__hipotesis-para-la-desaparicion-del-pico-ojos-del-salado_1193" => 10923,
        "grabados__hipotesis-para-la-destruccion-de-la-casa-de-federico-gonzalez_1191" => 10925,
        "grabados__hipotesis-para-la-destruccion-de-villa-aldobrandini_126" => 10913,
        "grabados__hipotesis-para-la-verificacion-de-los-danos-producidos-por-u_1190" => 10926,
        "grabados__hipotesis-para-un-terremoto-en-la-ciudad-de-paris_127" => 10912,
        "grabados__hopper_1558" => 10900,
        "grabados__la-comida-de-los-argentinos_1479" => 10901,
        "grabados__la-historia-de-la-destruccion-del-mundo_1834" => 10884,
        "grabados__logos_122" => 10917,
        "grabados__los-caballos-de-hierro_728" => 10863,
        "grabados__los-crimenes-politicos_695" => 10868,
        "grabados__mamacuna_328" => 10876,
        "grabados__memoria-de-america_1935" => 10879,
        "grabados__panteon-de-agrippa_499" => 10872,
        "grabados__panteon-de-agrippa-roma_476" => 10869,
        "grabados__partenon_497" => 10873,
        "grabados__partenon-atenas_475" => 10871,
        "grabados__proyecto-de-bar_1229" => 10914,
        "grabados__templo-de-khonsu_498" => 10874,
        "grabados__templo-de-khonsu-karnak_474" => 10870,
        //9 
        "libros__achille-mauri_1872" => 10579,
        "libros__acuario_1379" => 10629,
        "libros__ad-infinitum_1517" => 10631,
        "libros__ad-infinitum-acuario_1370" => 10628,
        "libros__ad-infinitum-caraguata_1384" => 10627,
        "libros__ad-infinitum-cardones_1385" => 10623,
        "libros__ad-infinitum-la-tempestad_1428" => 10612,
        "libros__ad-infinitum-manifestaciones_1455" => 10626,
        "libros__ad-infinitum-medusas_1425" => 10625,
        "libros__ad-infinitum-palmeras_1426" => 10632,
        "libros__ad-infinitum-tempestad_1367" => 10595,
        "libros__biblios_1516" => 10596,
        "libros__bosque_1820" => 10588,
        "libros__ciudades-de-plata_1507" => 10605,
        "libros__cortaderia_1819" => 10589,
        "libros__eiphnh_1866" => 10581,
        "libros__el-aleph_1864" => 10583,
        "libros__el-dios-desconocido_388" => 10521,
        "libros__el-libro-de-arena_1838" => 10586,
        "libros__el-pais-de-plata_1513" => 10599,
        "libros__el-poder-y-la-gloria_1512" => 10600,
        "libros__fiesta_369" => 10526,
        "libros__florence-baranger-bedel_1818" => 10590,
        "libros__fulgur_404" => 10513,
        "libros__fulgur-ii_405" => 10514,
        "libros__fulgur-iii_406" => 10510,
        "libros__fulgur-v_408" => 10511,
        "libros__fulgur-vi_409" => 10512,
        "libros__fulguriv_407" => 10515,
        "libros__hipotesis-para-el-incendio-de-una-iglesia_1547" => 10594,
        "libros__identities_1378" => 10630,
        "libros__la-memoria-de-la-humanidad_1837" => 10587,
        "libros__la-tempestad_1859" => 10585,
        "libros__las-ciudades-de-plata_198" => 10578,
        "libros__los-castillos-de-arena_316" => 10532,
        "libros__los-fundamentos_301" => 10547,
        "libros__manifestaciones_1493" => 10611,
        "libros__mas-alla-de-dios_1437" => 10617,
        "libros__new-york-skyline_1581" => 10593,
        "libros__radiograf-a-de-la-pampa_1454" => 10613,
        "libros__radiografia-de-la-pampa_1434" => 10619,
        "libros__rosa-argentea_1863" => 10584,
        "libros__summa-geometrica_1448" => 10616,
        "libros__tango_515" => 10508,
        "libros__varios_1505" => 10606,
        "libros__zen_402" => 10516,
        //10
        "pinturas__antinomia_1916" => 11577,
        "pinturas__aproximacion-a-la-nada_552" => 11530,
        "pinturas__aproximacion-al-infinito_554" => 11528,
        "pinturas__aproximacion-al-mal_1520" => 11594,
        "pinturas__autorretrato_799" => 11360,
        "pinturas__ciudad-invisible_1810" => 11582,
        "pinturas__el-arbol-de-la-ciencia-del-bien-y-del-mal_590" => 11495,
        "pinturas__el-borde-de-dios_615" => 11472,
        "pinturas__el-borde-de-la-nada_667" => 11425,
        "pinturas__el-dia_679" => 11413,
        "pinturas__el-eterno-retorno_519" => 11542,
        "pinturas__el-gran-oceano_666" => 11426,
        "pinturas__el-horizonte-de-plata_737" => 11402,
        "pinturas__el-llano-en-llamas_262" => 11564,
        "pinturas__el-pais-de-plata_214" => 11544,
        "pinturas__el-pais-de-plata-aer-aqua-ignis-terra_368" => 11576,
        "pinturas__el-rastro-de-dios_664" => 11428,
        "pinturas__el-rio-inmovil_650" => 11440,
        "pinturas__flo_798" => 11361,
        "pinturas__hipotesis-para-la-sombra-de-un-punto_571" => 11512,
        "pinturas__hipotesis-para-la-sombra-de-una-circunferencia_764" => 11384,
        "pinturas__hipotesis-para-la-sombra-de-una-raya_572" => 11511,
        "pinturas__hipotesis-para-un-dia-dificil-de-olvidar_1518" => 11596,
        "pinturas__historias-de-la-noche_547" => 11532,
        "pinturas__hormiga-argentina_643" => 11447,
        "pinturas__la-ciudad-invisible_1542" => 11593,
        "pinturas__la-ciudad-sobre-el-rio-inmovil_386" => 11543,
        "pinturas__la-garra-de-dios_662" => 11430,
        "pinturas__la-mar-oceana_360" => 11548,
        "pinturas__la-noche_680" => 11412,
        "pinturas__la-puerta-del-paraiso_522" => 11539,
        "pinturas__la-rosa-blindada_586" => 11498,
        "pinturas__la-sombra-de-dios_578" => 11506,
        "pinturas__las-1001-noches_1519" => 11595,
        "pinturas__las-ciudades-de-plata_276" => 11561,
        "pinturas__las-luces-y-las-sombras_581" => 11503,
        "pinturas__las-montanas-de-plata_227" => 11571,
        "pinturas__los-caballos-de-hierro_730" => 11405,
        "pinturas__mas-alla-de-dios_1436" => 11597,
        "pinturas__memoria-de-america_338" => 11555,
        "pinturas__memoria-de-la-patria-en-penumbras-el-llano-en-llamas_563" => 11519,
        "pinturas__oceano_549" => 11531,
        "pinturas__oso-invisible_653" => 11439,
        "pinturas__paradoxa_744" => 11400,
        "pinturas__r0830-p07_649" => 11441,
        "pinturas__rapsodia-del-cielo-1_1877" => 11580,
        "pinturas__rapsodia-del-cielo-3_1879" => 11578,
        "pinturas__rapsodias-del-cielo-2_1878" => 11579,
        "pinturas__reubicacion-del-horizonte-y-su-sombra_575" => 11508,
        "pinturas__summa-stellarum_1432" => 11598,
        "pinturas__un-largo-camino_569" => 11514,
        "pinturas___557" => 11525,
        //11
        "relieves__alguien-suena_472" => 11286,
        "relieves__aproximacion-al-infinito_1786" => 11353,
        "relieves__camita_760" => 11273,
        "relieves__de-rerum-natura_190" => 11324,
        "relieves__deus-ecce-deus-i_455" => 11288,
        "relieves__deus-ecce-deus-ii_456" => 11289,
        "relieves__el-desierto-de-los-tartaros-invertir-orden-fotos-c-681_551" => 11277,
        "relieves__el-llano-en-llamas_496" => 11285,
        "relieves__el-rayo-que-no-cesa_411" => 11295,
        "relieves__el-tirano_183" => 11330,
        "relieves__eros-y-tanatos_184" => 11329,
        "relieves__goneril_1816" => 11332,
        "relieves__hellas_1484" => 11356,
        "relieves__hipotesis-para-la-sombra-de-un-agujero_538" => 11281,
        "relieves__hipotesis-para-la-sombra-de-un-tajo_550" => 11278,
        "relieves__historias-del-mar_534" => 11284,
        "relieves__la-comida-de-los-argentinos_1477" => 11358,
        "relieves__la-noche-herida_1804" => 11336,
        "relieves__las-ciudades-de-plata_1694" => 11354,
        "relieves__las-manifestaciones-de-agosto_1200" => 11359,
        "relieves__medalla-concurso-trapiche_349" => 11298,
        "relieves__medalla-premio-moet-chandon_736" => 11275,
        "relieves__memoria-de-america_1918" => 11320,
        "relieves__memoria-de-america-en-tinieblas_1919" => 11318,
        "relieves__memoria-de-america-en-tinieblas-i_1917" => 11319,
        "relieves__mensaje-de-arecibo_348" => 11299,
        "relieves__rapsodia-del-mar-negro_1903" => 11323,
        "relieves__regan_1817" => 11331,
        "relieves__singularitas_1787" => 11352,
        "relieves__stella-neutroni_1798" => 11342,
        "relieves__summa-stellarum_1801" => 11339,
        "relieves__supernova_548" => 11279 ,
        "relieves__thalassa_1815" => 11333,
        "relieves__vortex_1800" => 11340,
        //12 
        "rollos__alpha-omega_524" => 11217,
        "rollos__apocalipsis-1-8_525" => 11216,
        "rollos__apocalipsis-21-6_526" => 11215,
        "rollos__apocalipsis-22-17_527" => 11214,
        "rollos__documenta_1506" => 11268,
        "rollos__el-mensaje-de-arecibo_355" => 11249,
        "rollos__ignis_1776" => 11256,
        "rollos__ignis-ii_1778" => 11258,
        "rollos__ignis-iii_1780" => 11223,
        "rollos__ignis-iv_483" => 11219,
        "rollos__ignis-ix_488" => 11220,
        "rollos__ignis-v_484" => 11221,
        "rollos__ignis-vi_1779" => 11257,
        "rollos__ignis-vii_486" => 11222,
        "rollos__ignis-viii_487" => 11218,
        "rollos__ignis-x_1777" => 11254,
        "rollos__ignis-xi_1782" => 11259,
        "rollos__ignis-xii_491" => 11260,
        "rollos__la-sombra-de-dios_796" => 11208,
        "rollos__memoria-de-america_722" => 11212,
        "rollos__odiseo_1833" => 11253,
        "rollos__paradise-lost_1754" => 11263,
        "rollos__sepher-i_1913" => 11250,
        "rollos__summa-stellarum_726" => 11211,
        "rollos__verbum_742" => 11245,
        "rollos__verbum-ii_1771" => 11262,
        "rollos__verbum-iii_414" => 11244,
        "rollos__verbum-iv_415" => 11240,
        "rollos__verbum-ix_420" => 11241,
        "rollos__verbum-v_416" => 11261,
        "rollos__verbum-vi_417" => 11242,
        "rollos__verbum-vii_1772" => 11243,
        "rollos__verbum-viii_419" => 11237,
        "rollos__verbum-x_421" => 11236,
        "rollos__verbum-xi_422" => 11231,
        "rollos__verbum-xiii_424" => 11238,
        "rollos__verbum-xiv_425" => 11232,
        "rollos__verbum-xix_435" => 11233,
        "rollos__verbum-xv_426" => 11234,
        "rollos__verbum-xvi_429" => 11235,
        "rollos__verbum-xvii_430" => 11227,
        "rollos__verbum-xviii_431" => 11228,
        "rollos__verbum-xx_436" => 11226,
        "rollos__verbum-xxi_437" => 11229,
        "rollos__verbum-xxii_439" => 11224,
        "rollos__verbum-xxiii_440" => 11225,
        "rollos__verbum-xxiv_441" => 11230,
        "rollos__verbum-xxv_442" => 11239,
        "rollos__verbum-xxvii_473" => 11210,
        "rollos__yo-soy-el-que-soy_358" => 11248,
        //13
        "fotografias__aproximacion-a-los-suenos_801" => 10636,
        "fotografias__buenos-aires-skyline_1580" => 10665,
        "fotografias__cardon_1374" => 10785,
        "fotografias__casa-en-miramar_1373" => 10786,
        "fotografias__cortaderas_1576" => 10669,
        "fotografias__cr-menes-pol-ticos_1475" => 10724,
        "fotografias__de-rerum-natura_1874" => 10640,
        "fotografias__deus-ex-machina-catedral-de-cadiz_1873" => 10641,
        "fotografias__deus-ex-machina-sant-agnese-roma_1382" => 10781,
        "fotografias__deus-ex-machina-santi-giovanni-e-paolo-venecia_1502" => 10713,
        "fotografias__deus-ex-machina-trinit-paris_1381" => 10782,
        "fotografias__f0894-f09_1554" => 10689,
        "fotografias__flo_1414" => 10753,
        "fotografias__hipotesis-para-el-colapso-de-un-edificio_373" => 10633,
        "fotografias__iluminaciones_1418" => 10749,
        "fotografias__jeronimo_1377" => 10784,
        "fotografias__la-esfinge-de-los-hielos_1447" => 10735,
        "fotografias__la-ira-de-dios_1549" => 10693,
        "fotografias__la-mar-oceana_1577" => 10668,
        "fotografias__la-rosa-blindada_1870" => 10643,
        "fotografias__la-tempestad_1574" => 10671,
        "fotografias__manifestaciones_1497" => 10779,
        "fotografias__manifestaciones-caterva_1386" => 10718,
        "fotografias__new-york-skyline_1579" => 10666,
        "fotografias__nyc_1424" => 10744,
        "fotografias__palacio-sarmiento_1875" => 10639,
        "fotografias__palazzo-cagnola_1883" => 10637,
        "fotografias__r0875-f09_1532" => 10704,
        "fotografias__r0876-f09_1421" => 10746,
        "fotografias__r0877-f09_1521" => 10712,
        "fotografias__r0878-f09_1534" => 10702,
        "fotografias__r0886-f09_1524" => 10710,
        "fotografias__r0887-f09_1525" => 10709,
        "fotografias__r0893-f09_1527" => 10707,
        "fotografias__r0895-f09_1526" => 10708,
        "fotografias__r0896-f09_1265" => 10793,
        "fotografias__r0898-f09_1267" => 10792,
        "fotografias__r0899-f09_1268" => 10791,
        "fotografias__r0901-f10_1555" => 10688,
        "fotografias__r0902-f10_1272" => 10790,
        "fotografias__r0903-f10_1273" => 10789,
        "fotografias__r0905-f10_1274" => 10788,
        "fotografias__r0906-f10_1275" => 10787,
        "fotografias__r0924-f10_1439" => 10742,
        "fotografias__r0925-f10_1438" => 10743,
        "fotografias__r0938-f10_1531" => 10705,
        "fotografias__r0939-f10_1446" => 10736,
        "fotografias__r0941-f10_1556" => 10687,
        "fotografias__r0942-f10_1557" => 10686,
        "fotografias__r0999-f11_1536" => 10700,
        "fotografias__r1001-f11_1538" => 10699,
        "fotografias__r1068af13_1578" => 10667,
        "fotografias__r1101-l13_1584" => 10663,
        "fotografias__r1117-f14_1575" => 10670,
        "fotografias__rapsodia-i_1844" => 10654,
        "fotografias__rapsodia-ii_1846" => 10656,
        "fotografias__redentore_1415" => 10752,
        "fotografias__s-t_1533" => 10703,
        "fotografias__salute_1416" => 10751,
        "fotografias__san-jorge_1876" => 10638,
        "fotografias__skyline_1869" => 10644,
        "fotografias__summa-geometrica_1462" => 10731,
        "fotografias__trinite_1501" => 10714,
        "sony-dsc-27" => 10662,
        "sony-dsc-31" => 10706,
        "sony-dsc-32" => 10737,
        "sony-dsc-33" => 10738,
        "sony-dsc-34" => 10739,
        "sony-dsc-35" => 10741,
    ];
    //count($thumbnails_ids) = 469 

    $aditional_fields = [];
    foreach ($thumbnails_ids as $name => $thumbnail_id){
        //connection to test2
        $postmetas = DB::connection('mysql')->table('wp_postmeta')->where('post_id', $thumbnail_id)->get();

        $fields = [
            '_wp_attached_file', 'numero_registro', 'clase', 'lugar', 'serie', 'fecha_inicio', 'fecha_fin', 'dimensiones', 'tecnica', 'ediciones', 'nombre'
        ];

        foreach ($postmetas as $postmeta){
            if ($postmeta->meta_key == '_wp_attached_file'){
                $aditional_fields[$thumbnail_id]['_wp_attached_file'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'numero_registro'){
                $aditional_fields[$thumbnail_id]['numero_registro'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'clase'){
                $aditional_fields[$thumbnail_id]['clase'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'lugar'){
                $aditional_fields[$thumbnail_id]['lugar'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'serie'){
                $aditional_fields[$thumbnail_id]['serie'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'fecha_inicio'){
                $aditional_fields[$thumbnail_id]['fecha_inicio'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'fecha_fin'){
                $aditional_fields[$thumbnail_id]['fecha_fin'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'dimensiones'){
                $aditional_fields[$thumbnail_id]['dimensiones'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key =='tecnica'){
                $aditional_fields[$thumbnail_id]['tecnica'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'ediciones'){
                $aditional_fields[$thumbnail_id]['ediciones'] = $postmeta->meta_value ?? ''; 
            } 
            if ($postmeta->meta_key == 'nombre'){
                $aditional_fields[$thumbnail_id]['nombre'] = $postmeta->meta_value ?? ''; 
            } 
        }
        
        foreach ($fields as $field){
            if (!in_array($field, array_keys($aditional_fields[$thumbnail_id]))){
                $aditional_fields[$thumbnail_id][$field] = ''; 
            }
        }
    }
    
    $path = storage_path('app/public/xmls/jacquesbedel.test2 copy.xml');
    $target = storage_path('app/public/xmls/nuevo.xml');
    $object = simplexml_load_file($path);

    //$namespaces = $object->getNamespaces(true);
    //armo array con los portfolios originales ya que no es iterable en el objeto
    $portfolios = [
        $object->channel->item[0],
        $object->channel->item[1],
        $object->channel->item[2],
        $object->channel->item[3],
        $object->channel->item[4],
        $object->channel->item[5],
        $object->channel->item[6],
        $object->channel->item[7],
        $object->channel->item[8],
        $object->channel->item[9],
        $object->channel->item[10],
        $object->channel->item[11],
        $object->channel->item[12],
        $object->channel->item[13],
    ];
    
    $portfolios_ids = 1000;
    $portfoliosNuevos = [];
    $galerias = [];
    $thumbnailsNames = [];

    foreach ($portfolios as $i => $portfolio){    
        $postmetas = $portfolio->ppppostmeta;
            foreach ($postmetas as $meta){
                if ($meta->pppmeta_key == "///_portfolio_settingsXXX"){
                    $string = $meta->pppmeta_value->__toString();
                    //limpio "///" del comienzo y "XXX" del final
                    $clean = substr($string, 3, -3);
                    $deserializado = unserialize($clean);

                    $items = [];
                    foreach ($deserializado['items'] as $item){
                        $key1 = explode('__', $item);
                        $key = explode('_', $key1[1]);
                        $obraName = $key[0];
                        if (!isset($items[$obraName])){
                            $items[$obraName] = [$item];
                        } else {
                            $items[$obraName][] = $item;
                        }
                    }

                    $items_thumbnail = [];
                    foreach ($deserializado['items_thumbnail'] as $item_thumbnail){
                        $key1 = explode('__', $item_thumbnail);
                        $key = explode('_', $key1[1]);
                        $obraName = $key[0];  
                        if (!isset($items_thumbnail[$obraName])){
                            $items_thumbnail[$obraName] = [$item_thumbnail];
                        } else {
                            $items_thumbnail[$obraName][] = $item_thumbnail;
                        }
                    }

                    $items_name = [];
                    foreach ($deserializado['items_name'] as $item_name){
                        $key1 = explode('__', $item_name);
                        if (isset($key1[1])){
                            $key = explode('_', $key1[1]); 
                            $obraName = $key[0];   
                        }   
                        if (!isset($items_name[$obraName])){
                            $items_name[$obraName] = [$item_name];
                        } else {
                            $items_name[$obraName][] = $item_name;
                        }
                    }
                    
                    //excepciones x obras mal nombradas
                    $galeryNames = array_keys($items);
                    foreach ($galeryNames as $galeryName){
                        if ($galeryName == 'r0927-f10'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-32'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                        if ($galeryName == 'r0926-f10'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-33'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                        if ($galeryName == 'r0934-f10'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-34'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                        if ($galeryName == 'r0935-f10'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-31'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                        if ($galeryName == 'r0932-f10'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-35'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                        if ($galeryName == 'paraguay'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if (substr($item_name, 0, 4) == 'sony'){
                                    $items_name[$galeryName][] = $item_name;
                                } 
                            }
                        }
                        if ($galeryName == 'obelisco'){
                            foreach ($deserializado['items_name'] as $item_name){
                                if ($item_name == 'sony-dsc-27'){
                                    $items_name[$galeryName][] = $item_name;
                                }
                            }
                        }
                    }
                    $galerias[$i]['items'] = $items;
                    $galerias[$i]['items_thumbnail'] = $items_thumbnail;
                    $galerias[$i]['items_name'] = $items_name;
                    //armo los portfolio settins que seran serializados de nuevo
                    foreach ($galeryNames as $galeryName){
                        $thumbnailId = $thumbnails_ids[$items_name[$galeryName][0]];

                        $galerias[$i][$galeryName]['portfolio_settings'] = [
                            "layout" => "content-full-width",
                            "portfolio-layout" => "with-left-portfolio",
                            "portfolio-slider" => "true",
                            "items" => $items[$galeryName],
                            "items_thumbnail" => $items_thumbnail[$galeryName],
                            "items_name" => $items_name[$galeryName],
                            "meta_title" => [
                                "numero-registro" => "Número Registro",
                                "serie" => "Serie",
                                "clase" => "Clase",
                                "lugar" => "Lugar",
                                "fecha-inicio" => "Fecha Inicio",
                                "fecha-fin" => "Fecha Fin",
                                "dimensiones" => "Dimensiones",
                                "tecnica" => "Técnica",
                                "ediciones" => "Ediciones"
                            ],
                            "meta_class" => [
                                "numero-registro" => "fa fa-cab",
                                "serie" => "fa fa-mortar-board",
                                "clase" => "fa fa-pencil",
                                "lugar" => "fa fa-map-marker",
                                "fecha-inicio" => "fa fa-pencil",
                                "fecha-fin" => "fa fa-pencil",
                                "dimensiones" => "fa fa-pencil",
                                "tecnica" => "fa fa-pencil",
                                "ediciones" => "fa fa-pencil"
                            ],
                            "meta_value" =>  [
                                "numero-registro" => $aditional_fields[$thumbnail_id]['numero_registro'],
                                "serie" => $aditional_fields[$thumbnail_id]['serie'],
                                "clase" => $aditional_fields[$thumbnail_id]['clase'],
                                "lugar" => $aditional_fields[$thumbnail_id]['lugar'],
                                "fecha-inicio" => $aditional_fields[$thumbnail_id]['fecha_inicio'],
                                "fecha-fin" => $aditional_fields[$thumbnail_id]['fecha_fin'],
                                "dimensiones" => $aditional_fields[$thumbnail_id]['dimensiones'],
                                "tecnica" => $aditional_fields[$thumbnail_id]['tecnica'],
                                "ediciones" => $aditional_fields[$thumbnail_id]['ediciones'],
                            ]
                        ]; 
                        
                        $serializado = serialize($galerias[$i][$galeryName]['portfolio_settings']);
                        $post_id = $portfolios_ids ++;
                        $visibleName = str_replace('-', ' ', $galeryName);
                        $upperName = ucfirst($visibleName);
                        $thumbnailId = $thumbnails_ids[$items_name[$galeryName][0]];

                        //creo los nuevos items (portfolios) ya en el objeto
                        $itemchild = $object->channel->addChild('item');
                        $itemchild->title = '///' . $upperName . '/id' . $post_id . 'XXX';
                        $itemchild->link = 'https://test2.jacquesbedel.com/dt_portfolios/' . $galeryName . '/';
                        $itemchild->pubDate = "Wed, 21 Jun 2023 20:19:14 +0000";
                        $itemchild->dddcreator = "///adminXXX";
                        $itemchild->guid = "https://test2.jacquesbedel.com/?post_type=dt_portfolios&p=" . $post_id;
                        $itemchild->description = new SimpleXmlElement('<description>///XXX</description>');
                        $itemchild->cccencoded = '///XXX';
                        $itemchild->eeeencoded = '///XXX';
                        $itemchild->ppppost_id = $post_id;
                        $itemchild->ppppost_date = "///2023-06-21 17:19:14XXX";
                        $itemchild->ppppost_date_gmt = "///2023-06-21 20:19:14XXX";
                        $itemchild->ppppost_modified = "///2023-08-12 07:26:26XXX";
                        $itemchild->ppppost_modified_gmt = "///2023-08-12 10:26:26XXX";
                        $itemchild->pppcomment_status = "///closedXXX";
                        $itemchild->pppping_status = "///closedXXX";
                        $itemchild->pppstatus = "///publishXXX";
                        $itemchild->ppppost_parent = "0";
                        $itemchild->pppmenu_order = "0";
                        $itemchild->ppppost_type = "///dt_portfoliosXXX";
                        $itemchild->ppppost_password = "///XXX";
                        $itemchild->pppis_sticky = "0";
                        
                        //category
                        $cat_nicename = lcfirst(substr($portfolio->category->__toString(), 3, -3));
                        $category = $itemchild->addChild('category', $portfolio->category->__toString());
                        $category->addAttribute('domain', "portfolio_entries");
                        $category->addAttribute('nicename', $cat_nicename);
                        $subcategoryString = substr($portfolio->title->__toString(), 3, -3);
                        $subcat_nicename = lcfirst($subcategoryString);
                        $subcategoryString2 = '///' . $subcategoryString . 'XXX';
                        $subcategory = $itemchild->addChild('category', $subcategoryString2);
                        $subcategory->addAttribute('domain', "portfolio_entries");
                        $subcategory->addAttribute('nicename', $subcat_nicename);

                        //postmeta
                        $metas = [
                            [
                                "pppmeta_key" => "///_edit_lastXXX",
                                "pppmeta_value" => "///1XXX"
                            ],
                            [
                                "pppmeta_key" => "///_portfolio_settingsXXX",
                                "pppmeta_value" => "///" . $serializado . "XXX"
                            ],
                            [
                                "pppmeta_key" => "///_wp_old_slugXXX",
                                "pppmeta_value" => "///brave-man-2XXX"
                            ],
                            [
                                "pppmeta_key" => "///_thumbnail_idXXX",
                                "pppmeta_value" => "///" . $thumbnailId . "XXX"
                            ],
                            [
                                "pppmeta_key" => "///_wp_old_slugXXX",
                                "pppmeta_value" => "///proposing-love-2XXX"
                            ]
                        ];
                        foreach ($metas as $i => $meta){
                            $postmeta = $itemchild->addChild('ppppostmeta');
                            $postmeta->addChild('pppmeta_key', $metas[$i]['pppmeta_key']);
                            $postmeta->addChild('pppmeta_value', $metas[$i]['pppmeta_value']);
                        }

                        $categoryClean = substr($portfolio->category->__toString(), 3, -3);
                        $subcategoryClean = substr($portfolio->title->__toString(), 3, -3);
                        $portfoliosNuevos[$categoryClean][$subcategoryClean][] = $itemchild;
                    }
                }
            }
    }
    
    //elimino los primeros 14 items (portfolios originales)
    for ($x = 0; $x < 14; $x++){
       unset($object->channel->item[0]);
    } 

    //dd($portfoliosNuevos);
    //Guardo el archivo nuevo
    $object->asXML($target);
});


Route::get('/mediaFile', function () {
    $destiny = storage_path('app/public/media.xml');
    $thumbnails_ids = [
        //0
         'dibujos__casa-indihar_1920' => 10372,
         'dibujos__dibujos-1969_1720' => 10373,
         //1
         "o__casa-en-abril_969" => 10944,
         "o__rutalsur_977" => 10938,
         "o__casa-en-bella-vista_968" => 10945,
         "o__casa-en-jose-ignacio_957" => 10952,
         "o__el-reposo_947" => 10961,
         "o__casa-en-martindale-country-club_941" => 10967,
         "o__los-abrojos_933" => 10975,
         "o__konstandt-m_927" => 10981,
         "o__casa-en-las-condes_921" => 10987,
         "o__casa-en-club-sociedad-hebraica-argentina_915" => 10993,
         "o__casa-en-newman-country-club_909" => 10999,
         "o__la-calderona_903" => 11005,
         "o__casa-en-talar-de-pacheco_891" => 11017,
         "o__casa-en-la-horqueta_885" => 11023,
         "o__casa-en-pilar_879" => 11029,
         "o__casa-en-san-isidro_873" => 11035,
         "o__casa-en-la-horqueta-i_866" => 11041,
         "o__casa-en-la-isla-nordelta_860" => 11047,
         "o__casa-en-colonia_853" => 11053,
         "o__malabrigo_842" => 11059,
         "o__casa-en-la-pedrera_1928" => 11067,
         "o__casa-indihar_1892" => 11074,
         "o__casa-en-san-jorge-village_1882" => 11083,
         "o__casa-en-laguna-del-sol_1066" => 11101,
         "o__virtuani_1060" => 11107,
         "o__casa-en-tortugas-country-club_1029" => 11125,
         //2
         "proyectos__abelleyra_978" => 10806,
         'proyectos__casa-en-abril_1699' => 10846,
         'proyectos__casa-en-bella-vista_1717' => 10828,
         'proyectos__casa-en-cardon-miramar-links_1736' => 10823,
         'proyectos__casa-en-club-sociedad-hebraica-argentina_1700' => 10845,
         'proyectos__casa-en-cumelen-country-club_845' => 10809,
         'proyectos__casa-en-jose-ignacio_1716' => 10829,
         'proyectos__casa-en-la-horqueta_1003' => 10862,
         'proyectos__casa-en-la-isla-nordelta_1697' => 10848,
         'proyectos__casa-en-las-condes_1712' => 10833,
         'proyectos__casa-en-martindale-country-club_1713' => 10832,
         'proyectos__casa-en-newman-country-club_1698' => 10847,
         'proyectos__casa-en-olivos_986' => 10803,
         'proyectos__casa-en-pacheco_1014' => 10854,
         'proyectos__casa-en-pilar_1692' => 10850,
         'proyectos__casa-en-san-jorge_1707' => 10838,
         'proyectos__casa-en-tigre_1008' => 10859,
         'proyectos__casa-en-tortugas-country-club_1691' => 10851,
         'proyectos__el-reposo_1730' => 10826,
         'proyectos__la-calderona_1721' => 10827,
         'proyectos__proyecto-casa-alhadeff_1929' => 10811,
         'proyectos__tajamares-de-la-pedrera_1696' => 10849,
         //3
         'interiores__casa-en-bella-vista_1662' => 10425,
         'interiores__casa-en-club-sociedad-hebraica-argentina_1663' => 10424,
         'interiores__casa-en-el-talar-de-pacheco_1682' => 10407,
         'interiores__casa-en-jose-ignacio_1624' => 10463,
         'interiores__casa-en-la-celina_1678' => 10411,
         'interiores__casa-en-las-condes_1654' => 10433,
         'interiores__casa-en-martindale-country-club_1623' => 10464,
         'interiores__casa-en-nordelta_1687' => 10402,
         'interiores__casa-en-san-isidro_1643' => 10444,
         'interiores__la-calderona_1639' => 10448,
         'interiores__malabrigo_1616' => 10471,
         'interiores__r0062-e70_1688' => 10401,
         'interiores__r0120-e72_1689' => 10400,
         'interiores__santiago-del-estero_1601' => 10486,
         'sony-dsc-25' => 10484,
        //4 equipamientos
         'equipamiento__cama_1222' => 10394,
         'equipamiento__candelabro_1218' => 10395,
         'equipamiento__mesa-comedor_1227' => 10390,
         'equipamiento__mesa-living_1228' => 10389,
         'equipamiento__silla-en-metal_1224' => 10393,
         'equipamiento__lampara_1215' => 10398,
         'equipamiento__silla_1213' => 10399,
        //5
         "otros__natura-aurea_1186" => 10380,
         "otros__piggy-pet_1235" => 10383,
         "otros__concurso-telon-para-el-teatro-colon_1690" => 10382,
        //6
        'dibujos__adios-completo-ramon-oliveira-cezar-h_723' => 11135,
        'dibujos__amarras_1165' => 11203,
        'dibujos__auto-de-fe_162' => 11189,
        'dibujos__carancho-del-monte_365' => 11142,
        'dibujos__ciudad-imaginaria_325' => 11149,
        'dibujos__ciudadela-rivero_225' => 11180,
        'dibujos__el-pais-de-plata_224' => 11181,
        'dibujos__el-poder_332' => 11146,
        'dibujos__fragmento-del-inexplicable-penasco-de-prometeo_364' => 11143,
        'dibujos__hipotesis-para-el-incendio-de-un-cubo_149' => 11200,
        'dibujos__hipotesis-para-la-destruccion-de-una-fabrica_117' => 11202,
        'dibujos__la-esfera_273' => 11172,
        'dibujos__la-gloria_333' => 11145,
        'dibujos__la-mar-oceana_733' => 11132,
        'dibujos__la-rosa-blindada_331' => 11147,
        'dibujos__las-ciudades-de-plata_1695' => 11182,
        'dibujos__las-montanas-de-plata_234' => 11178,
        'dibujos__las-prisiones_155' => 11194,
        'dibujos__libro-el-aire-todo-rene-bedel-imprenta-colombo-buenos-air_1125' => 11207,
        'dibujos__libro-el-aire-todo-rene-bedel-imprenta-colombo-buenos-ai_1127' => 11205,
        'dibujos__logos_330' => 11148,
        'dibujos__los-horizontes-de-plata_296' => 11154,
        'dibujos__vivienda-economica_1203' => 11201,
        //7
        'esculturas__acropolis_542' => 11619,
        'esculturas__aequatio_1195' => 11754,
        'esculturas__al-acero_216' => 11692,
        'esculturas__alter-ego_1101' => 11810,
        'esculturas__anillo-brazalete-y-diadema_470' => 11631,
        'esculturas__antimateria_1136' => 11783,
        'esculturas__aproximacion-a-la-incertidumbre_1735' => 11708,
        'esculturas__aproximacion-al-infinito_1175' => 11761,
        'esculturas__aproximacion-al-infinito-la-gran-muralla_1173' => 11759,
        'esculturas__argos_1134' => 11785,
        'esculturas__caja-de-reflexionii_1164' => 11769,
        'esculturas__caracu_359' => 11673,
        'esculturas__ciudad-para-armar_1107' => 11804,
        'esculturas__constituyentes-de-santa-fe_352' => 11678,
        'esculturas__cromosombra_1075' => 11833,
        'esculturas__culebron-hembra_428' => 11652,
        'esculturas__culebron-macho_427' => 11653,
        'esculturas__das-schloss_356' => 11675,
        'esculturas__dura-lex-sed-lex_1909' => 11696,
        'esculturas__el-agua-herida_546' => 11618,
        'esculturas__el-arquitecto-de-america_1476' => 11722,
        'esculturas__el-basilisco_1734' => 11709,
        'esculturas__el-dios-desconocido_391' => 11660,
        'esculturas__el-dios-reencontrado_387' => 11662,
        'esculturas__el-dueno-del-mundo_467' => 11634,
        'esculturas__el-entrevero_1860' => 11700,
        'esculturas__el-espejo_1129' => 11788,
        'esculturas__el-gran-limite_218' => 11691,
        'esculturas__el-huevo-y-la-serpiente_533' => 11620,
        'esculturas__el-improbable-romance-entre-el-plano-y-la-esfera_1907' => 11698,
        'esculturas__el-manantial_529' => 11623,
        'esculturas__el-ojo-de-dios_1109' => 11802,
        'esculturas__el-rayo-que-no-cesa_1196' => 11753,
        'esculturas__el-tunel_1168' => 11766,
        'esculturas__el-zahir_740' => 11613,
        'esculturas__el-zonda_478' => 11629,
        'esculturas__energeia_1167' => 11767,
        'esculturas__eros-y-tanatos_186' => 11701,
        'esculturas__espejismo_1122' => 11791,
        'esculturas__fragmento-del-inexplicable-penasco-de-prometeo_357' => 11674,
        'esculturas__hipotesis-para-el-colapso-de-un-edificio_373' => 11669,
        'esculturas__hipotesis-para-la-definicion-de-una-sombra_1207' => 11744,
        'esculturas__hipotesis-para-la-desaparicion-de-una-piedra_1176' => 11758,
        'esculturas__hipotesis-para-la-destruccion-de-un-cubo_231' => 11686,
        'esculturas__hipotesis-para-la-destruccion-del-puente-avellaneda_1197' => 11752,
        'esculturas__hipotesis-para-la-destruccion-del-puente-de-l-anglois_1194' => 11755,
        'esculturas__hipotesis-para-una-prision_1172' => 11762,
        'esculturas__jeronimo_176' => 11707,
        'esculturas__la-cabeza-de-ramirez_172' => 11710,
        'esculturas__la-comida-de-los-argentinos_1479' => 11599,
        'esculturas__la-letra-silenciosa_741' => 11612,
        'esculturas__la-mano-de-dios_1457' => 11726,
        'esculturas__la-sabiduria_179' => 11702,
        'esculturas__la-sentencia_131' => 11737,
        'esculturas__la-siesta-del-general-gueemes_171' => 11711,
        'esculturas__la-soledad_178' => 11703,
        'esculturas__la-vuelta-al-hogar_123' => 11742,
        'esculturas__labyrinthos_370' => 11672,
        'esculturas__las-llaves-del-reino_532' => 11621,
        'esculturas__las-manifestaciones-de-septiembre_1201' => 11749,
        'esculturas__los-desastres-de-la-guerra_138' => 11734,
        'esculturas__los-due-os-del-mundo_1480' => 11720,
        'esculturas__los-duenos-del-mundo_448' => 11649,
        'esculturas__los-espacios-vitales_169' => 11713,
        'esculturas__los-principes-australes_344' => 11680,
        'esculturas__mamacuna_1236' => 11741,
        'esculturas__maqueta-concurso-argencard_385' => 11663,
        'esculturas__mercator_372' => 11670,
        'esculturas__microcosmos_1138' => 11781,
        'esculturas__moderator_1908' => 11697,
        'esculturas__monumento-a-los-caidos-en-las-malvinas_400' => 11656,
        'esculturas__monumento-al-gral-mosconi_324' => 11681,
        'esculturas__monumento-al-iv-centenario-de-la-fundacion-de-la-ciudad-de-b_323' => 11682,
        'esculturas__monumento-al-prisionero-politico_140' => 11732,
        'esculturas__natura-aurea_1180' => 11606,
        'esculturas__nicolas_177' => 11706,
        'esculturas__obelisco-comercial-del-plata_445' => 11650,
        'esculturas__obelisco-juri_390' => 11661,
        'esculturas__obelisco-punta-del-este_1482' => 11718,
        'esculturas__objeto-paradojal_1130' => 11787,
        'esculturas__opforma_1087' => 11821,
        'esculturas__premio-alto-palermo_401' => 11655,
        'esculturas__premio-banco-republica_477' => 11630,
        'esculturas__premio-emilio-poblet_1486' => 11716,
        'esculturas__premio-hoechst_403' => 11654,
        'esculturas__res-vitae_1774' => 11705,
        'esculturas__scientia-premio-inet_517' => 11624,
        'esculturas__tensiones_1198' => 11751,
        'esculturas__thalassa_1901' => 11699,
        'esculturas__trofeo-fondo-nacional-de-las-artes_434' => 11651,
        'esculturas__victoria_468' => 11633,
        'esculturas__vivienda-economica-modelo-super-compacto-para-armar_1202' => 11748,
        //8
        "grabados__auto-de-fe_175" => 10897,
        "grabados__concentracion-de-la-imagen-en-un-punto_1178" => 10930,
        "grabados__desarrollo-de-perspectiva-en-un-punto-acueducto-del-gard_1188" => 10929,
        "grabados__el-panteon-de-agrippa-roma_1832" => 10885,
        "grabados__el-partenon-atenas_1824" => 10893,
        "grabados__espacios-imaginarios_1189" => 10928,
        "grabados__estudio-para-la-reconstruccion-de-un-papel-de-dibujo_1192" => 10924,
        "grabados__hipotesis-para-el-bombardeo-de-la-basilica-de-san-pedro_129" => 10910,
        "grabados__hipotesis-para-el-desarrollo-de-una-mancha-de-humedad_128" => 10911,
        "grabados__hipotesis-para-la-desaparicion-del-pico-ojos-del-salado_1193" => 10923,
        "grabados__hipotesis-para-la-destruccion-de-la-casa-de-federico-gonzalez_1191" => 10925,
        "grabados__hipotesis-para-la-destruccion-de-villa-aldobrandini_126" => 10913,
        "grabados__hipotesis-para-la-verificacion-de-los-danos-producidos-por-u_1190" => 10926,
        "grabados__hipotesis-para-un-terremoto-en-la-ciudad-de-paris_127" => 10912,
        "grabados__hopper_1558" => 10900,
        "grabados__la-comida-de-los-argentinos_1479" => 10901,
        "grabados__la-historia-de-la-destruccion-del-mundo_1834" => 10884,
        "grabados__logos_122" => 10917,
        "grabados__los-caballos-de-hierro_728" => 10863,
        "grabados__los-crimenes-politicos_695" => 10868,
        "grabados__mamacuna_328" => 10876,
        "grabados__memoria-de-america_1935" => 10879,
        "grabados__panteon-de-agrippa_499" => 10872,
        "grabados__panteon-de-agrippa-roma_476" => 10869,
        "grabados__partenon_497" => 10873,
        "grabados__partenon-atenas_475" => 10871,
        "grabados__proyecto-de-bar_1229" => 10914,
        "grabados__templo-de-khonsu_498" => 10874,
        "grabados__templo-de-khonsu-karnak_474" => 10870,
        //9 
        "libros__achille-mauri_1872" => 10579,
        "libros__acuario_1379" => 10629,
        "libros__ad-infinitum_1517" => 10631,
        "libros__ad-infinitum-acuario_1370" => 10628,
        "libros__ad-infinitum-caraguata_1384" => 10627,
        "libros__ad-infinitum-cardones_1385" => 10623,
        "libros__ad-infinitum-la-tempestad_1428" => 10612,
        "libros__ad-infinitum-manifestaciones_1455" => 10626,
        "libros__ad-infinitum-medusas_1425" => 10625,
        "libros__ad-infinitum-palmeras_1426" => 10632,
        "libros__ad-infinitum-tempestad_1367" => 10595,
        "libros__biblios_1516" => 10596,
        "libros__bosque_1820" => 10588,
        "libros__ciudades-de-plata_1507" => 10605,
        "libros__cortaderia_1819" => 10589,
        "libros__eiphnh_1866" => 10581,
        "libros__el-aleph_1864" => 10583,
        "libros__el-dios-desconocido_388" => 10521,
        "libros__el-libro-de-arena_1838" => 10586,
        "libros__el-pais-de-plata_1513" => 10599,
        "libros__el-poder-y-la-gloria_1512" => 10600,
        "libros__fiesta_369" => 10526,
        "libros__florence-baranger-bedel_1818" => 10590,
        "libros__fulgur_404" => 10513,
        "libros__fulgur-ii_405" => 10514,
        "libros__fulgur-iii_406" => 10510,
        "libros__fulgur-v_408" => 10511,
        "libros__fulgur-vi_409" => 10512,
        "libros__fulguriv_407" => 10515,
        "libros__hipotesis-para-el-incendio-de-una-iglesia_1547" => 10594,
        "libros__identities_1378" => 10630,
        "libros__la-memoria-de-la-humanidad_1837" => 10587,
        "libros__la-tempestad_1859" => 10585,
        "libros__las-ciudades-de-plata_198" => 10578,
        "libros__los-castillos-de-arena_316" => 10532,
        "libros__los-fundamentos_301" => 10547,
        "libros__manifestaciones_1493" => 10611,
        "libros__mas-alla-de-dios_1437" => 10617,
        "libros__new-york-skyline_1581" => 10593,
        "libros__radiograf-a-de-la-pampa_1454" => 10613,
        "libros__radiografia-de-la-pampa_1434" => 10619,
        "libros__rosa-argentea_1863" => 10584,
        "libros__summa-geometrica_1448" => 10616,
        "libros__tango_515" => 10508,
        "libros__varios_1505" => 10606,
        "libros__zen_402" => 10516,
        //10
        "pinturas__antinomia_1916" => 11577,
        "pinturas__aproximacion-a-la-nada_552" => 11530,
        "pinturas__aproximacion-al-infinito_554" => 11528,
        "pinturas__aproximacion-al-mal_1520" => 11594,
        "pinturas__autorretrato_799" => 11360,
        "pinturas__ciudad-invisible_1810" => 11582,
        "pinturas__el-arbol-de-la-ciencia-del-bien-y-del-mal_590" => 11495,
        "pinturas__el-borde-de-dios_615" => 11472,
        "pinturas__el-borde-de-la-nada_667" => 11425,
        "pinturas__el-dia_679" => 11413,
        "pinturas__el-eterno-retorno_519" => 11542,
        "pinturas__el-gran-oceano_666" => 11426,
        "pinturas__el-horizonte-de-plata_737" => 11402,
        "pinturas__el-llano-en-llamas_262" => 11564,
        "pinturas__el-pais-de-plata_214" => 11544,
        "pinturas__el-pais-de-plata-aer-aqua-ignis-terra_368" => 11576,
        "pinturas__el-rastro-de-dios_664" => 11428,
        "pinturas__el-rio-inmovil_650" => 11440,
        "pinturas__flo_798" => 11361,
        "pinturas__hipotesis-para-la-sombra-de-un-punto_571" => 11512,
        "pinturas__hipotesis-para-la-sombra-de-una-circunferencia_764" => 11384,
        "pinturas__hipotesis-para-la-sombra-de-una-raya_572" => 11511,
        "pinturas__hipotesis-para-un-dia-dificil-de-olvidar_1518" => 11596,
        "pinturas__historias-de-la-noche_547" => 11532,
        "pinturas__hormiga-argentina_643" => 11447,
        "pinturas__la-ciudad-invisible_1542" => 11593,
        "pinturas__la-ciudad-sobre-el-rio-inmovil_386" => 11543,
        "pinturas__la-garra-de-dios_662" => 11430,
        "pinturas__la-mar-oceana_360" => 11548,
        "pinturas__la-noche_680" => 11412,
        "pinturas__la-puerta-del-paraiso_522" => 11539,
        "pinturas__la-rosa-blindada_586" => 11498,
        "pinturas__la-sombra-de-dios_578" => 11506,
        "pinturas__las-1001-noches_1519" => 11595,
        "pinturas__las-ciudades-de-plata_276" => 11561,
        "pinturas__las-luces-y-las-sombras_581" => 11503,
        "pinturas__las-montanas-de-plata_227" => 11571,
        "pinturas__los-caballos-de-hierro_730" => 11405,
        "pinturas__mas-alla-de-dios_1436" => 11597,
        "pinturas__memoria-de-america_338" => 11555,
        "pinturas__memoria-de-la-patria-en-penumbras-el-llano-en-llamas_563" => 11519,
        "pinturas__oceano_549" => 11531,
        "pinturas__oso-invisible_653" => 11439,
        "pinturas__paradoxa_744" => 11400,
        "pinturas__r0830-p07_649" => 11441,
        "pinturas__rapsodia-del-cielo-1_1877" => 11580,
        "pinturas__rapsodia-del-cielo-3_1879" => 11578,
        "pinturas__rapsodias-del-cielo-2_1878" => 11579,
        "pinturas__reubicacion-del-horizonte-y-su-sombra_575" => 11508,
        "pinturas__summa-stellarum_1432" => 11598,
        "pinturas__un-largo-camino_569" => 11514,
        "pinturas___557" => 11525,
        //11
        "relieves__alguien-suena_472" => 11286,
        "relieves__aproximacion-al-infinito_1786" => 11353,
        "relieves__camita_760" => 11273,
        "relieves__de-rerum-natura_190" => 11324,
        "relieves__deus-ecce-deus-i_455" => 11288,
        "relieves__deus-ecce-deus-ii_456" => 11289,
        "relieves__el-desierto-de-los-tartaros-invertir-orden-fotos-c-681_551" => 11277,
        "relieves__el-llano-en-llamas_496" => 11285,
        "relieves__el-rayo-que-no-cesa_411" => 11295,
        "relieves__el-tirano_183" => 11330,
        "relieves__eros-y-tanatos_184" => 11329,
        "relieves__goneril_1816" => 11332,
        "relieves__hellas_1484" => 11356,
        "relieves__hipotesis-para-la-sombra-de-un-agujero_538" => 11281,
        "relieves__hipotesis-para-la-sombra-de-un-tajo_550" => 11278,
        "relieves__historias-del-mar_534" => 11284,
        "relieves__la-comida-de-los-argentinos_1477" => 11358,
        "relieves__la-noche-herida_1804" => 11336,
        "relieves__las-ciudades-de-plata_1694" => 11354,
        "relieves__las-manifestaciones-de-agosto_1200" => 11359,
        "relieves__medalla-concurso-trapiche_349" => 11298,
        "relieves__medalla-premio-moet-chandon_736" => 11275,
        "relieves__memoria-de-america_1918" => 11320,
        "relieves__memoria-de-america-en-tinieblas_1919" => 11318,
        "relieves__memoria-de-america-en-tinieblas-i_1917" => 11319,
        "relieves__mensaje-de-arecibo_348" => 11299,
        "relieves__rapsodia-del-mar-negro_1903" => 11323,
        "relieves__regan_1817" => 11331,
        "relieves__singularitas_1787" => 11352,
        "relieves__stella-neutroni_1798" => 11342,
        "relieves__summa-stellarum_1801" => 11339,
        "relieves__supernova_548" => 11279 ,
        "relieves__thalassa_1815" => 11333,
        "relieves__vortex_1800" => 11340,
        //12 
        "rollos__alpha-omega_524" => 11217,
        "rollos__apocalipsis-1-8_525" => 11216,
        "rollos__apocalipsis-21-6_526" => 11215,
        "rollos__apocalipsis-22-17_527" => 11214,
        "rollos__documenta_1506" => 11268,
        "rollos__el-mensaje-de-arecibo_355" => 11249,
        "rollos__ignis_1776" => 11256,
        "rollos__ignis-ii_1778" => 11258,
        "rollos__ignis-iii_1780" => 11223,
        "rollos__ignis-iv_483" => 11219,
        "rollos__ignis-ix_488" => 11220,
        "rollos__ignis-v_484" => 11221,
        "rollos__ignis-vi_1779" => 11257,
        "rollos__ignis-vii_486" => 11222,
        "rollos__ignis-viii_487" => 11218,
        "rollos__ignis-x_1777" => 11254,
        "rollos__ignis-xi_1782" => 11259,
        "rollos__ignis-xii_491" => 11260,
        "rollos__la-sombra-de-dios_796" => 11208,
        "rollos__memoria-de-america_722" => 11212,
        "rollos__odiseo_1833" => 11253,
        "rollos__paradise-lost_1754" => 11263,
        "rollos__sepher-i_1913" => 11250,
        "rollos__summa-stellarum_726" => 11211,
        "rollos__verbum_742" => 11245,
        "rollos__verbum-ii_1771" => 11262,
        "rollos__verbum-iii_414" => 11244,
        "rollos__verbum-iv_415" => 11240,
        "rollos__verbum-ix_420" => 11241,
        "rollos__verbum-v_416" => 11261,
        "rollos__verbum-vi_417" => 11242,
        "rollos__verbum-vii_1772" => 11243,
        "rollos__verbum-viii_419" => 11237,
        "rollos__verbum-x_421" => 11236,
        "rollos__verbum-xi_422" => 11231,
        "rollos__verbum-xiii_424" => 11238,
        "rollos__verbum-xiv_425" => 11232,
        "rollos__verbum-xix_435" => 11233,
        "rollos__verbum-xv_426" => 11234,
        "rollos__verbum-xvi_429" => 11235,
        "rollos__verbum-xvii_430" => 11227,
        "rollos__verbum-xviii_431" => 11228,
        "rollos__verbum-xx_436" => 11226,
        "rollos__verbum-xxi_437" => 11229,
        "rollos__verbum-xxii_439" => 11224,
        "rollos__verbum-xxiii_440" => 11225,
        "rollos__verbum-xxiv_441" => 11230,
        "rollos__verbum-xxv_442" => 11239,
        "rollos__verbum-xxvii_473" => 11210,
        "rollos__yo-soy-el-que-soy_358" => 11248,
        //13
        "fotografias__aproximacion-a-los-suenos_801" => 10636,
        "fotografias__buenos-aires-skyline_1580" => 10665,
        "fotografias__cardon_1374" => 10785,
        "fotografias__casa-en-miramar_1373" => 10786,
        "fotografias__cortaderas_1576" => 10669,
        "fotografias__cr-menes-pol-ticos_1475" => 10724,
        "fotografias__de-rerum-natura_1874" => 10640,
        "fotografias__deus-ex-machina-catedral-de-cadiz_1873" => 10641,
        "fotografias__deus-ex-machina-sant-agnese-roma_1382" => 10781,
        "fotografias__deus-ex-machina-santi-giovanni-e-paolo-venecia_1502" => 10713,
        "fotografias__deus-ex-machina-trinit-paris_1381" => 10782,
        "fotografias__f0894-f09_1554" => 10689,
        "fotografias__flo_1414" => 10753,
        "fotografias__hipotesis-para-el-colapso-de-un-edificio_373" => 10633,
        "fotografias__iluminaciones_1418" => 10749,
        "fotografias__jeronimo_1377" => 10784,
        "fotografias__la-esfinge-de-los-hielos_1447" => 10735,
        "fotografias__la-ira-de-dios_1549" => 10693,
        "fotografias__la-mar-oceana_1577" => 10668,
        "fotografias__la-rosa-blindada_1870" => 10643,
        "fotografias__la-tempestad_1574" => 10671,
        "fotografias__manifestaciones_1497" => 10779,
        "fotografias__manifestaciones-caterva_1386" => 10718,
        "fotografias__new-york-skyline_1579" => 10666,
        "fotografias__nyc_1424" => 10744,
        "fotografias__palacio-sarmiento_1875" => 10639,
        "fotografias__palazzo-cagnola_1883" => 10637,
        "fotografias__r0875-f09_1532" => 10704,
        "fotografias__r0876-f09_1421" => 10746,
        "fotografias__r0877-f09_1521" => 10712,
        "fotografias__r0878-f09_1534" => 10702,
        "fotografias__r0886-f09_1524" => 10710,
        "fotografias__r0887-f09_1525" => 10709,
        "fotografias__r0893-f09_1527" => 10707,
        "fotografias__r0895-f09_1526" => 10708,
        "fotografias__r0896-f09_1265" => 10793,
        "fotografias__r0898-f09_1267" => 10792,
        "fotografias__r0899-f09_1268" => 10791,
        "fotografias__r0901-f10_1555" => 10688,
        "fotografias__r0902-f10_1272" => 10790,
        "fotografias__r0903-f10_1273" => 10789,
        "fotografias__r0905-f10_1274" => 10788,
        "fotografias__r0906-f10_1275" => 10787,
        "fotografias__r0924-f10_1439" => 10742,
        "fotografias__r0925-f10_1438" => 10743,
        "fotografias__r0938-f10_1531" => 10705,
        "fotografias__r0939-f10_1446" => 10736,
        "fotografias__r0941-f10_1556" => 10687,
        "fotografias__r0942-f10_1557" => 10686,
        "fotografias__r0999-f11_1536" => 10700,
        "fotografias__r1001-f11_1538" => 10699,
        "fotografias__r1068af13_1578" => 10667,
        "fotografias__r1101-l13_1584" => 10663,
        "fotografias__r1117-f14_1575" => 10670,
        "fotografias__rapsodia-i_1844" => 10654,
        "fotografias__rapsodia-ii_1846" => 10656,
        "fotografias__redentore_1415" => 10752,
        "fotografias__s-t_1533" => 10703,
        "fotografias__salute_1416" => 10751,
        "fotografias__san-jorge_1876" => 10638,
        "fotografias__skyline_1869" => 10644,
        "fotografias__summa-geometrica_1462" => 10731,
        "fotografias__trinite_1501" => 10714,
        "sony-dsc-27" => 10662,
        "sony-dsc-31" => 10706,
        "sony-dsc-32" => 10737,
        "sony-dsc-33" => 10738,
        "sony-dsc-34" => 10739,
        "sony-dsc-35" => 10741,
    ];

    $path = storage_path('app/public/mediaTest2COMPLETO.xml');
    $object = simplexml_load_file($path);
    //count(items) = 3561;

    for ($x = 0; isset($object->channel->item[$x]);){
        if (!in_array((int)$object->channel->item[$x]->ppppost_id, array_values($thumbnails_ids))){
            unset($object->channel->item[$x]);
        } else {
            while (isset($object->channel->item[$x]->ppppostmeta[2])){
                unset($object->channel->item[$x]->ppppostmeta[2]);    
            }
            $x++;
        }
    }
    
    $object->asXML($destiny);

});