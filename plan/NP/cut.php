<?php

function min_roll_search($cut_array){
    /** Функция поиска минимального размера рулона в массиве cut_array */
    $min_roll_size = 1000000; // начальное значение для сравнения
    for ($i = 0; $i < count($cut_array); $i++){
        if ($cut_array[$i]['width'] < $min_roll_size){
            $min_roll_size = $cut_array[$i]['width'];
        }
    }
    return $min_roll_size;
}

function shuffle_cut_array_with_fixed_height($cut_array){
    $result = [];
    $groups = [];

    foreach ($cut_array as $item) {
        $groups[$item['height']][] = $item;
    }

    foreach ($groups as $group) {
        shuffle($group);
        $result = array_merge($result, $group);
    }

    return $result;
}

/**
 * Алгоритм раскроя:
 * 1)из массива initial_order выбираем значения в массив cut_array
 * initial_order => cut_array { filter , pp_height , pp_width }
 * пример: initial_order{ [AF1601], 284, [48], [199], 60, 85,3} => cut_array{AF1601, 48, 199}
 *
 * 2)вместо #rolls_need добавляем необходимое количество строк в cut_array :
 * пример: initial_order{ AF1601, 284, 48, 199, 60, 85,[3]} => cut_array{AF1601, 48, 199;
 *                                                                       AF1601, 48, 199;
 *                                                                       AF1601, 48, 199}
 * ---------------надо добавить суммирование позиций по 0,2+0,8 к примеру и затем подбивать количество необходимое
 * ---------------надо добавить возможность кроить одну позицию в одной бухте
 *
 * 3)отсортировываем массив по высоте валков (pp_height)
 *
 * 4)находим минимальной ширины рулон в cut_array
 *
 * 4)начиная с начала массива cut_array подряд добавляем рулоны в temp_roll, проверяя:
 *  4.1.не последний ли это рулон в массиве? если  последний? -------------------------------------------------->
 *      4.2. будет ли остаток рулона после добавления этой позиции больше чем минимальный ролик в массиве cut_array? Потому что если остаток
 *           будет меньше мы не сможем ни чего больше в рулон добавить.
 *           если ДА то добавляем cut_array[$x] -> temp_roll, если НЕТ, то переходим к следующему рулону. -------------------------------->
 *
 *
 *
 */

/** Функция раскроя */
function cut_execute($cut_array, $width_of_main_roll, $max_gap, $min_gap){

    $not_cutted_rolls = array(); // массив не вошедших в раскрой позиций

    /** @var  $count_of_completed_rolls - количество успешно собраных рулонов */
    $count_of_completed_rolls = 0;

    /** @var  $round_complete - переменная показывает резултьативность текущего прохода по списку */

    /** @var  cut_marker маркер выполнения раскроя */
    $cut_marker = true;

    //$counter = 0;                                                                 --------надо грохнуть

    /** @var  $temp_roll - временный рулон, используется в роли буфера*/
    $temp_roll = array();

    /** @var  $total_width - суммарная ширина позиций, собранных в рулон*/
    $total_width = 0;

    //$safety_lock=100;                                                             --------надо грохнуть

    /** @var  $roll_complete маркер успешного сложения рулона*/
    $roll_complete = false;

    /** @var  $min_width_of_roll - минимальная используемая ширина рулона, например: 1175 = 1200 - 25(максимально-допустимый отход) */
    $min_width_of_roll = $width_of_main_roll - $max_gap;

    /** @var  $max_width_of_roll - максимальная используемая ширина рулона, например: 1195 = 1200 - 5(минимальный обрезок) */
    $max_width_of_roll = $width_of_main_roll - $min_gap;

    /** @var  $completed_rolls - массив собранных бухт */
    $completed_rolls = array();

    /** @var  $start_cycle - переменная указывающая с какой позиции стоит начинать сборку рулона */
    $start_cycle =0;

    $round_complete = true;

    /** Сортировка cut-массива по валкам */
    //sort_cut_array();------------------------------------------------------------------------------ ЗАГЛУШКА

    for ($a = 0; $a < 150; $a++){ /** Количество повторов при не результативном цикле */

        /** очистка массива используемого в качестве буфера */
        array_splice($temp_roll,0);

        /** @var  $total_width - сброс счетчика ширины рулона */
        $total_width = 0;

        /** Если рулон не собрался -> смещаем начало обхода на 1 позицию */
        if ($round_complete){
            $start_cycle =0;
            $round_complete = false;
        } else{
            /** перемешивание элементов массива в случае если предыдущий проход был не результативен
            перемешивание массива происходит, внутри групп с одной высотой валков */
            $cut_array = shuffle_cut_array_with_fixed_height($cut_array);
        }
        /** процедура сборки одного рулона */
        for($x = $start_cycle; $x < count($cut_array); $x++){
                /** находим минимальной ширины рулон */
                $min_roll_size = min_roll_search($cut_array);
                /** находим остаток рулона после добавления текущего рулона */
                $ostatok = $max_width_of_roll - $total_width - $cut_array[$x]['width'];
                /** если остаток рулона после добавления этой позиции будет больше чем минимальный ролик в массиве cut_array
                * или остаток равен минимальному размеру рулона или попадает в диапазон требуемого остатка */
                if (($ostatok > $min_roll_size) || ($ostatok == ($min_roll_size)) || (($ostatok > 5) and ($ostatok < 30))) {
                    /** добавляем в temp_roll cut_array[$x][width] */
                    array_push($temp_roll, $cut_array[$x]);
                    /** увеличиваем суммарную ширину собираемой бухты */
                    $total_width = $total_width + $cut_array[$x]['width'];
                }else{
                /** если остаток рулона после добавления этой позиции будет меньше чем минимальный ролик в массиве -> не трогаем его и переходим дальше*/
                    continue;
                }
                /** если ширина рулона попадает в требуемый диапазон */
                if (($total_width < $max_width_of_roll) and ($total_width > $min_width_of_roll)){
                    /** убираем из  cut_array позиции, которые вошли temp_rolls*/
                    for($y=0; $y < count($temp_roll);$y++){
                        $deleted = false;
                        for ($c = 0; $c < count($cut_array);$c++){
                            if(($temp_roll[$y] == $cut_array[$c])&(!$deleted)){
                                /** удаляем из cut_array запись, существующую в temp_array */
                                array_splice($cut_array,$c,1);
                                $deleted = true;
                            }
                        }
                    }
                    /** добавляем собранный рулон в completed_rolls */

                    /** добавляем в массив собранных рулонов */

                    array_push($completed_rolls, $temp_roll);

                    /** увеличиваем счетчик успешно собранных рулонов */
                    $count_of_completed_rolls++;
                    $round_complete = true;

                }
                //  $x++;
            }
        /** конец процедуры сборки одного рулона */
    }

    $not_cutted_rolls = $cut_array;
    return array($completed_rolls, $not_cutted_rolls  );

}