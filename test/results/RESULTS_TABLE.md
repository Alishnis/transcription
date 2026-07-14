# Diarization test results

Ground truth (по словам пользователя):
- **abdr_man** — интервью, 2 спикера
- **kyzym** — подкаст, 5 спикеров
- **kudyrtu** — обсуждение, 4 спикера (в названии файла 5 имён)

Промпты:
- **v1** = voice characteristics (текущий продакшн-промпт)
- **v2** = count-first (двухпроходный: сначала сосчитать голоса, потом расставить turns)

Конфигурации:
- **whole-file** = один запрос на весь файл
- **chunked60** = файл режется на 60-сек куски, диаризация на каждом куске отдельно (иллюстрирует проблему фрагментации)

| Файл | Ожидается | Конфигурация | Turns | Найдено спикеров | Совпадает? | Заметки |
|---|---|---|---|---|---|---|
| abdr_man | 2 | whole-file / v1 | 16 | 2 | ✅ | |
| abdr_man | 2 | whole-file / v2 | 20 | 2 | ✅ | |
| abdr_man | 2 | chunked60 / v1 | — | — | | см. json, по чанкам отдельно |
| abdr_man | 2 | chunked60 / v2 | — | — | | см. json, по чанкам отдельно |
| kyzym | 5 | whole-file / v1 | | | | |
| kyzym | 5 | whole-file / v2 | | | | |
| kyzym | 5 | chunked60 / v1 | | | | |
| kyzym | 5 | chunked60 / v2 | | | | |
| kudyrtu | 4 | whole-file / v1 | | | | |
| kudyrtu | 4 | whole-file / v2 | | | | |
| kudyrtu | 4 | chunked60 / v1 | | | | |
| kudyrtu | 4 | chunked60 / v2 | | | | |

## Как заполнять

Файлы с результатами лежат в `test/results/`:
- `<файл>__wholefile__<вариант>.json` — `{"turns": [...], "stats": {"turn_count", "distinct_speakers", "speaker_seconds"}}`
- `<файл>__chunked60__<вариант>.json` — массив чанков, у каждого свой `stats`

Открой нужный `.json`, возьми `stats.turn_count` и `stats.distinct_speakers`, впиши в таблицу и отметь ✅/❌ по совпадению с ожидаемым числом спикеров.

Чтобы досчитать недостающие конфигурации (квота API часто отказывает — сейчас скрипт пропускает такие случаи без ожидания):

```bash
php test/diarization_lab.php > test/results/_run.log 2>&1
```

Скрипт кэширует уже готовые `.json` — при повторных запусках досчитывает только то, чего не хватает.
