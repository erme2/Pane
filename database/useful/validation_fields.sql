select
    fv.field_validation_id, t.name, f.name,  vt.name, fv.value
from
    map_field_validations fv
        join map_fields f on fv.field_id = f.field_id
        join map_validation_types vt on fv.validation_type_id = vt.validation_type_id
        join map_tables t on f.table_id = t.table_id
